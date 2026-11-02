<?php

namespace App\Services\Video;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Services\Ai\AiGatewayService;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use App\Services\Image\ImageStudioService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Video Studio (Service C): prompt → storyboard → shots → one film.
 *
 * The flow, and why it's split this way:
 *
 *   1. createDraft() — the director LLM writes a shot list and every shot
 *      gets a price. Nothing is rendered and only the (cents-cheap) LLM
 *      call is billed. The client reads the plan, edits any shot, and sees
 *      the exact maximum it will cost.
 *   2. start() — the client approves. The wallet must cover the quote for
 *      everything still to render (on top of anything else already
 *      rendering), so a film can't start that the wallet can't finish.
 *   3. advance() — run every minute by the scheduler (video:advance), and
 *      opportunistically after a client polls: submits shots, polls the
 *      provider, downloads and bills each finished shot, and when all are
 *      in, stitches the film. Idempotent and lock-guarded, so the two
 *      triggers can overlap safely.
 *
 * Billing is per shot, when that shot is delivered: a film that fails
 * halfway costs only the shots that actually rendered, and each shot is
 * billed at most its quote (see billShot()).
 *
 * Pricing config shape (ServiceType::Video, same resolve rules as AI):
 *   ['multiplier' => 2.0, 'model_overrides' => ['google/veo-3.1' => 1.6], 'disabled_models' => [...]]
 */
class VideoStudioService
{
    public function __construct(
        private readonly AiGatewayService $ai,
        private readonly BillingEngine $billing,
        private readonly ServiceConfigRepository $configs,
        private readonly VideoModelCatalog $catalog,
        private readonly VideoDirector $director,
        private readonly VideoStitcher $stitcher,
    ) {}

    // ─── configuration ────────────────────────────────────────────────

    public function isConfigured(): bool
    {
        return $this->ai->isConfigured();
    }

    public function isEnabledFor(Company $company): bool
    {
        return $this->configs->clientOverride($company, ServiceType::Video)?->pricing_config['enabled'] ?? true;
    }

    public function setEnabledFor(Company $company, bool $enabled): void
    {
        $this->configs->setClientFlag($company, ServiceType::Video, 'enabled', $enabled);
    }

    /** @return array<string, mixed> */
    public function pricingFor(Company $company): array
    {
        return array_merge([
            'multiplier' => config('pingly.video.default_multiplier'),
            'model_overrides' => [],
            'disabled_models' => [],
        ], $this->configs->resolve($company, ServiceType::Video));
    }

    public function multiplierFor(Company $company, string $model): float
    {
        // Per-client multiplier = a blanket rate for that client, same rule as AI.
        $clientMultiplier = $this->configs->clientOverride($company, ServiceType::Video)?->pricing_config['multiplier'] ?? null;
        if ($clientMultiplier !== null) {
            return (float) $clientMultiplier;
        }

        $pricing = $this->pricingFor($company);

        return (float) ($pricing['model_overrides'][$model] ?? $pricing['multiplier']);
    }

    /**
     * Models this client may pick, with what the UI needs to build its
     * controls and show a price per second. Price is the client's price
     * (real × margin), never the raw cost.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableModelsFor(Company $company): array
    {
        $disabled = $this->pricingFor($company)['disabled_models'] ?? [];

        return collect($this->catalog->all())
            ->reject(fn ($m, $id) => in_array($id, $disabled, true))
            ->map(function ($m, $id) use ($company) {
                $prices = collect($m['resolutions'])->mapWithKeys(fn ($res) => [
                    $res => round($this->catalog->estimateShotCost($id, $res, '16:9', 1, true, 0) * $this->multiplierFor($company, $id), 4),
                ]);

                return [
                    'id' => $id,
                    'name' => $m['name'],
                    'tagline' => $m['tagline'],
                    'recommended' => $m['recommended'] ?? false,
                    'resolutions' => $m['resolutions'],
                    'aspect_ratios' => $m['aspect_ratios'],
                    'audio' => $m['audio'],
                    'max_references' => $m['max_references'],
                    'blocks_real_faces' => $m['blocks_real_faces'] ?? false,
                    'max_shot_seconds' => max($m['durations']),
                    'price_per_second' => $prices,
                ];
            })->values()->all();
    }

    // ─── 1. plan ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $input  validated: prompt, model, resolution, aspect_ratio, duration, generate_audio, language, style
     * @param  array<int, array{data: string, label: string, role: string}>  $references  data: URLs
     */
    public function createDraft(Company $company, User $user, array $input, array $references): VideoProject
    {
        $this->assertUsable($company);

        $disabled = $this->pricingFor($company)['disabled_models'] ?? [];
        $model = $this->catalog->get($input['model']);
        if (! $model || in_array($input['model'], $disabled, true)) {
            throw new RuntimeException('That video model is not available.');
        }

        // Seedance refuses photos of real people (ByteDance's deepfake
        // rule). Rather than fail at render time, a film with a real
        // person's photo goes to the model that can take one — the quote
        // the client approves is already the new model's price.
        $notice = null;
        $realPerson = collect($references)->contains(fn ($r) => ! empty($r['real_person']));
        if ($realPerson && ($model['blocks_real_faces'] ?? false)) {
            $alt = VideoModelCatalog::REAL_FACE_MODEL;
            if (in_array($alt, $disabled, true)) {
                throw new RuntimeException("{$model['name']} can't use photos of real people. Remove the photo, or ask support to enable Kling 3.0 Pro.");
            }
            $altModel = $this->catalog->get($alt);
            $notice = "{$model['name']} doesn't accept photos of real people, so this film uses {$altModel['name']} instead — the price shown is for {$altModel['name']}.";
            $input['model'] = $alt;
            if (! in_array($input['resolution'], $altModel['resolutions'], true)) {
                $input['resolution'] = end($altModel['resolutions']);
            }
            if (! in_array($input['aspect_ratio'], $altModel['aspect_ratios'], true)) {
                [$w, $h] = $this->catalog->size($input['aspect_ratio'], '720p');
                $input['aspect_ratio'] = $w > $h ? '16:9' : ($w < $h ? '9:16' : '1:1');
            }
            $model = $altModel;
        }
        foreach (['resolution' => 'resolutions', 'aspect_ratio' => 'aspect_ratios'] as $field => $list) {
            if (! in_array($input[$field], $model[$list], true)) {
                throw new RuntimeException("{$model['name']} doesn't support {$input[$field]}.");
            }
        }
        if (count($references) > $model['max_references']) {
            throw new RuntimeException("{$model['name']} accepts at most {$model['max_references']} reference images.");
        }

        $audio = (bool) $input['generate_audio'] && $model['audio'];
        $durations = $this->catalog->planShotDurations($input['model'], (int) $input['duration']);

        // Store the references before the (slow) LLM call so a failure
        // further down doesn't leave orphaned files with no project row —
        // the project is created first and deleted on failure.
        $project = VideoProject::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'prompt' => $input['prompt'],
            'model' => $input['model'],
            'resolution' => $input['resolution'],
            'aspect_ratio' => $input['aspect_ratio'],
            'target_duration' => (int) $input['duration'],
            'generate_audio' => $audio,
            'language' => $audio ? ($input['language'] ?? null) : null,
            'style' => $input['style'] ?? 'cinematic',
            'status' => 'draft',
        ]);

        try {
            $project->update(['references' => $this->storeReferences($project, $references)]);

            $storyboard = $this->director->plan(
                $company, $input['prompt'], $durations, $project->references ?? [],
                $project->language, $project->style, $project->aspect_ratio,
            );
        } catch (Throwable $e) {
            $this->deleteProject($project);

            if (! $e instanceof RuntimeException) {
                report($e); // a bug, not a refusal — keep it in the log
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException('Could not plan this video — try again.');
        }

        $storyboard['seed'] = random_int(1, 2_000_000_000);
        $storyboard['notice'] = $notice;
        $multiplier = $this->multiplierFor($company, $project->model);
        $refCount = count($project->references ?? []);

        DB::transaction(function () use ($project, $storyboard, $multiplier, $refCount) {
            $total = 0.0;
            foreach ($storyboard['shots'] as $i => $shot) {
                $quote = round($this->catalog->estimateShotCost(
                    $project->model, $project->resolution, $project->aspect_ratio, $shot['duration'], $project->generate_audio,
                    // the first shot's still becomes an extra reference for the rest when none were uploaded
                    $refCount ?: ($i > 0 ? 1 : 0),
                ) * $multiplier, 4);
                $total += $quote;

                $project->shots()->create([
                    'position' => $i,
                    'duration' => $shot['duration'],
                    'description' => $shot['description'],
                    'quoted_amount' => $quote,
                ]);
            }

            $project->update([
                'title' => Str::limit($storyboard['title'], 120, ''),
                'storyboard' => $storyboard,
                'estimated_cost' => $total,
            ]);
        });

        return $project->fresh('shots');
    }

    /** The client rewrote a shot before rendering it. */
    public function updateShot(VideoProject $project, VideoShot $shot, string $description): VideoShot
    {
        if ($project->isActive() || $shot->status === 'completed' || $shot->status === 'submitted') {
            throw new RuntimeException('This shot is already rendering or rendered.');
        }

        // A painted opening frame belongs to the old description.
        $shot->update(['description' => $description, 'keyframe_path' => null, 'keyframe_cost' => null]);

        return $shot;
    }

    // ─── 2. approve ───────────────────────────────────────────────────

    /** Approve a draft — or resume a failed project from its unfinished shots. */
    public function start(VideoProject $project): VideoProject
    {
        $company = $project->company;
        $this->assertUsable($company);

        if (! in_array($project->status, ['draft', 'failed'], true)) {
            throw new RuntimeException('This video is already '.$project->status.'.');
        }

        if ($project->shots()->count() > 1 && ! $this->stitcher->available()) {
            Log::critical('Video Studio: ffmpeg is not installed — multi-shot videos cannot be joined.');

            throw new RuntimeException('Videos longer than one shot are temporarily unavailable — contact support.');
        }

        DB::transaction(function () use ($project, $company) {
            // Lock the wallet row so two approvals at once can't both pass
            // the same balance check.
            $wallet = $company->wallet()->lockForUpdate()->first();

            $remaining = (float) $project->shots()->where('status', '!=', 'completed')->sum('quoted_amount');
            // Everything already promised to other renders (this project is
            // draft/failed, so it isn't counted in there itself).
            $reserved = $this->billing->reservedFor($company);

            if (! $wallet || (float) $wallet->balance < $remaining + $reserved) {
                $needed = number_format($remaining + $reserved - (float) ($wallet->balance ?? 0), 2);

                throw new RuntimeException("Not enough balance — top up at least \${$needed} to render this video.");
            }

            $project->shots()->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'error' => null, 'provider_job_id' => null]);
            $project->update(['status' => 'rendering', 'error' => null, 'started_at' => $project->started_at ?? now()]);
        });

        $this->advance($project->fresh());

        return $project->fresh('shots');
    }

    // ─── 3. render ────────────────────────────────────────────────────

    /** Move every in-flight project one step forward. Called by video:advance. */
    public function advanceAll(): int
    {
        $projects = VideoProject::whereIn('status', VideoProject::ACTIVE_STATUSES)->get();

        foreach ($projects as $project) {
            $this->advance($project);
        }

        return $projects->count();
    }

    public function advance(VideoProject $project): void
    {
        $lock = Cache::lock("video-project:{$project->id}", 900);

        if (! $lock->get()) {
            return; // someone else is already moving this project
        }

        try {
            $project->refresh();

            if ($project->status === 'rendering') {
                $this->pollSubmitted($project);
                $this->submitPending($project);
                $this->maybeFinishRendering($project);
            }

            if ($project->status === 'stitching') {
                $this->finish($project);
            }
        } catch (Throwable $e) {
            Log::error('Video Studio: advancing a project failed', ['project' => $project->id, 'error' => $e->getMessage()]);
            $this->fail($project, 'Something went wrong while rendering — you can resume, and you are only charged for finished shots.');
        } finally {
            $lock->release();
        }
    }

    private function pollSubmitted(VideoProject $project): void
    {
        $shots = $project->shots()->where('status', 'submitted')->get();

        foreach ($shots as $shot) {
            if ($shot->last_polled_at && $shot->last_polled_at->gt(now()->subSeconds(10))) {
                continue;
            }
            $shot->update(['last_polled_at' => now()]);

            try {
                $response = $this->http(30)->get(config('pingly.ai.openrouter_api_base')."/videos/{$shot->provider_job_id}");
            } catch (ConnectionException) {
                continue; // try again next tick
            }

            if ($response->failed()) {
                Log::warning('Video Studio: poll failed', ['shot' => $shot->id, 'status' => $response->status(), 'body' => $response->body()]);
                if ($response->status() === 404) {
                    $this->retryOrFail($project, $shot, 'The render job was lost upstream.');
                }

                continue;
            }

            $job = $response->json();

            match ($job['status'] ?? null) {
                'completed' => $this->collect($project, $shot, $job),
                'failed', 'cancelled', 'expired' => $this->retryOrFail($project, $shot, (string) ($job['error'] ?? $job['status'])),
                default => $shot->submitted_at?->lt(now()->subMinutes(45))
                    ? $this->retryOrFail($project, $shot, 'Timed out waiting for the render.')
                    : null,
            };

            if ($project->fresh()->status === 'failed') {
                return;
            }
        }
    }

    private function submitPending(VideoProject $project): void
    {
        $project->refresh();
        if ($project->status !== 'rendering') {
            return;
        }

        $inFlight = $project->shots()->where('status', 'submitted')->count();
        $slots = max(0, (int) config('pingly.video.parallel_shots') - $inFlight);
        if ($this->usesKeyframes($project)) {
            // Each submit first paints a keyframe (up to ~1 min) — keep one tick short.
            $slots = min($slots, 2);
        }

        // With no uploaded references, shot 1 is rendered alone first and a
        // still of its main character becomes the reference for every
        // other shot — the difference between one actor and eight
        // lookalikes. Only possible when ffmpeg is there to pull the still.
        // anchor_path: null = not pulled yet, '' = tried and gave up, path = ready.
        $needsAnchor = ! $this->usesKeyframes($project) && empty($project->references) && $project->anchor_path === null
            && $project->shots()->count() > 1 && $this->stitcher->available();

        if ($needsAnchor && $project->shots()->where('position', 0)->where('status', '!=', 'completed')->exists()) {
            $slots = $project->shots()->where('position', 0)->where('status', 'pending')->exists() ? min($slots, 1) : 0;
        }

        $pending = $project->shots()->where('status', 'pending')->orderBy('position')->limit($slots)->get();

        foreach ($pending as $shot) {
            $this->submit($project, $shot);

            if ($project->fresh()->status === 'failed') {
                return;
            }
        }
    }

    private function submit(VideoProject $project, VideoShot $shot): void
    {
        $storyboard = $project->storyboard;
        $planned = array_merge($storyboard['shots'][$shot->position] ?? [], [
            'description' => $shot->description,
            'duration' => $shot->duration,
        ]);

        $references = $project->references ?? [];

        if ($this->usesKeyframes($project)) {
            try {
                $frame = $this->keyframeFor($project, $shot, $planned);
            } catch (RuntimeException $e) {
                $shot->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $this->fail($project, 'Shot '.($shot->position + 1)."'s opening frame couldn't be painted — {$e->getMessage()} Edit the shot and resume.");

                return;
            }
            $prompt = $this->director->composePrompt($storyboard, $planned, $shot->position, $project->shots()->count(), 0, false)
                ."\nStart exactly from the given first frame: same people, faces, clothes, products and setting; animate it as described.";
            $body = [
                'model' => $project->model,
                'prompt' => $prompt,
                'duration' => $shot->duration,
                'resolution' => $project->resolution,
                'aspect_ratio' => $project->aspect_ratio,
                'generate_audio' => $project->generate_audio,
                'frame_images' => [['type' => 'image_url', 'image_url' => ['url' => $this->dataUrl($frame)], 'frame_type' => 'first_frame']],
            ];
        } else {
            $images = collect($references)->map(fn ($r) => $this->dataUrl($r['path']));
            $hasAnchor = $project->anchor_path && $shot->position > 0;
            if ($hasAnchor) {
                $images->push($this->dataUrl($project->anchor_path));
            }

            $prompt = $this->director->composePrompt($storyboard, $planned, $shot->position, $project->shots()->count(), count($references), (bool) $hasAnchor);
            $body = $this->referenceBody($project, $storyboard, $shot, $prompt, $images->all());
        }

        $shot->update(['rendered_prompt' => $prompt, 'attempts' => $shot->attempts + 1]);

        $this->send($project, $shot, $body);
    }

    /**
     * @param  array<int, string>  $images  data: URLs
     * @return array<string, mixed>
     */
    private function referenceBody(VideoProject $project, array $storyboard, VideoShot $shot, string $prompt, array $images): array
    {
        $images = collect($images);

        return array_filter([
            'model' => $project->model,
            'prompt' => $prompt,
            'duration' => $shot->duration,
            'resolution' => $project->resolution,
            'aspect_ratio' => $project->aspect_ratio,
            'generate_audio' => $project->generate_audio,
            // Same seed on every shot of a film nudges the model toward the
            // same rendering of the same description; not all honour it.
            'seed' => $storyboard['seed'] ?? null,
            'input_references' => $images->map(fn ($url) => ['type' => 'image_url', 'image_url' => ['url' => $url]])->values()->all() ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Paint (once) the still a keyframe model animates from. The client's
     * photos are the references; with none, shot 1's frame is the reference
     * for every later shot — the job the ffmpeg anchor does for
     * reference-based models.
     *
     * @param  array<string, mixed>  $planned
     */
    private function keyframeFor(VideoProject $project, VideoShot $shot, array $planned): string
    {
        if ($shot->keyframe_path && Storage::disk('local')->exists($shot->keyframe_path)) {
            return $shot->keyframe_path;
        }

        $refs = collect($project->references ?? [])->values();
        $images = $refs->map(fn ($r) => $this->dataUrl($r['path']))->all();
        $fromFirstShot = false;
        if (! $images && $shot->position > 0) {
            $first = $project->shots()->where('position', 0)->value('keyframe_path');
            if ($first && Storage::disk('local')->exists($first)) {
                $images[] = $this->dataUrl($first);
                $fromFirstShot = true;
            }
        }

        $board = $project->storyboard ?? [];
        $lines = ["Paint the opening frame of one shot of a professional ad film, as a single photorealistic film still (aspect ratio {$project->aspect_ratio})."];
        if (! empty($board['style_bible'])) {
            $lines[] = 'Look of the film: '.(is_string($board['style_bible']) ? $board['style_bible'] : json_encode($board['style_bible'], JSON_UNESCAPED_UNICODE));
        }
        $lines[] = 'This shot: '.$planned['description'];
        if ($refs->isNotEmpty()) {
            $lines[] = "The attached images are the client's real references:";
            foreach ($refs as $i => $r) {
                $lines[] = 'Image '.($i + 1).": {$r['role']} — {$r['label']}";
            }
            $lines[] = 'Keep every person exactly the same person — same face, features, skin tone and hair — and every product exactly as it is.';
        } elseif ($fromFirstShot) {
            $lines[] = 'The attached image is the first shot of this film — keep the same people, wardrobe and look.';
        }
        $lines[] = 'No text, captions or watermarks.';

        $aspect = in_array($project->aspect_ratio, ['16:9', '9:16', '1:1'], true) ? $project->aspect_ratio : '16:9';
        $rendered = app(ImageStudioService::class)->render(VideoModelCatalog::KEYFRAME_IMAGE_MODEL, implode("\n", $lines), $images, $aspect);

        $path = $project->storageDir().sprintf('/keyframe_%03d.%s', $shot->position, $rendered['ext']);
        Storage::disk('local')->put($path, $rendered['bytes']);
        $shot->update(['keyframe_path' => $path, 'keyframe_cost' => $rendered['cost']]);

        return $path;
    }

    private function usesKeyframes(VideoProject $project): bool
    {
        return ($this->catalog->get($project->model)['conditioning'] ?? null) === 'keyframe';
    }

    /** @param  array<string, mixed>  $body */
    private function send(VideoProject $project, VideoShot $shot, array $body): void
    {
        try {
            $response = $this->http(60)->post(config('pingly.ai.openrouter_api_base').'/videos', $body);
        } catch (ConnectionException $e) {
            Log::warning('Video Studio: submit could not connect', ['shot' => $shot->id, 'error' => $e->getMessage()]);
            $this->retryOrFail($project, $shot, 'Could not reach the render service.');

            return;
        }

        if ($response->failed()) {
            Log::error('Video Studio: submit rejected', ['shot' => $shot->id, 'status' => $response->status(), 'body' => $response->body()]);

            // A 4xx is the request itself (a parameter, or the content
            // filter) — resubmitting the same thing won't change the answer.
            if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429) {
                $shot->update(['status' => 'failed', 'error' => 'Rejected by the render service.']);
                $hasPerson = collect($project->references ?? [])->contains(fn ($r) => $r['role'] === 'character');
                $hint = $hasPerson && ($this->catalog->get($project->model)['blocks_real_faces'] ?? false)
                    ? ' If a reference is a photo of a real person, start a new video and tick "Real person" on it — that uses Kling 3.0 Pro, which accepts real faces.'
                    : '';
                $this->fail($project, 'Shot '.($shot->position + 1).' was rejected — it may have tripped the content filter. Edit it and resume.'.$hint);

                return;
            }

            $this->retryOrFail($project, $shot, 'The render service is busy.');

            return;
        }

        $shot->update([
            'status' => 'submitted',
            'provider_job_id' => $response->json('id'),
            'submitted_at' => now(),
            'last_polled_at' => now(),
            'error' => null,
        ]);
    }

    /** Download a finished shot, bill it, and (shot 1) pull the identity anchor. */
    private function collect(VideoProject $project, VideoShot $shot, array $job): void
    {
        $path = $project->storageDir().sprintf('/shot_%03d.mp4', $shot->position);
        $absolute = Storage::disk('local')->path($path);
        @mkdir(dirname($absolute), 0775, true);

        $url = $job['unsigned_urls'][0] ?? config('pingly.ai.openrouter_api_base')."/videos/{$shot->provider_job_id}/content?index=0";
        $download = $this->http(300)->sink($absolute)->get($url);

        if ($download->failed() || ! is_file($absolute) || filesize($absolute) < 1024) {
            Log::warning('Video Studio: download failed', ['shot' => $shot->id, 'status' => $download->status()]);

            return; // still 'submitted' — the next poll tries the download again
        }

        DB::transaction(function () use ($project, $shot, $job, $path) {
            $shot = VideoShot::lockForUpdate()->find($shot->id);
            if ($shot->status === 'completed') {
                return;
            }
            // A painted keyframe is part of this shot's cost; with no reported
            // video cost at all, billShot falls back to the quote.
            $videoCost = (float) ($job['usage']['cost'] ?? 0);
            $this->billShot($project, $shot, $videoCost > 0 ? $videoCost + (float) $shot->keyframe_cost : 0.0);
            $shot->update(['status' => 'completed', 'file_path' => $path, 'error' => null]);
        });

        if ($shot->position === 0 && ! $this->usesKeyframes($project) && empty($project->references) && $project->anchor_path === null && $this->stitcher->available()) {
            try {
                $anchor = $project->storageDir().'/anchor.jpg';
                $this->stitcher->still($absolute, Storage::disk('local')->path($anchor), max(0.5, $shot->duration * 0.4));
                $project->update(['anchor_path' => $anchor]);
            } catch (Throwable $e) {
                Log::warning('Video Studio: anchor still failed — continuing without it', ['project' => $project->id, 'error' => $e->getMessage()]);
                $project->update(['anchor_path' => '']); // stop waiting for it
            }
        }
    }

    /**
     * Real cost × margin, but never more than this shot's quote: the client
     * approved a price, and a provider repricing mid-render is Pingly's
     * problem, not theirs. If the provider reports no cost at all, the quote
     * is used (it's an estimate of exactly that cost × margin).
     */
    private function billShot(VideoProject $project, VideoShot $shot, float $rawCost): void
    {
        $multiplier = $this->multiplierFor($project->company, $project->model);
        $quote = (float) $shot->quoted_amount;
        $billed = $rawCost > 0 ? min(round($rawCost * $multiplier, 6), $quote) : $quote;

        if ($rawCost > 0 && $rawCost * $multiplier > $quote * 1.25) {
            Log::warning('Video Studio: shot cost well over quote — check catalog pricing', [
                'project' => $project->id, 'shot' => $shot->id, 'raw' => $rawCost, 'quote' => $quote,
            ]);
        }

        $event = $this->billing->recordUsage(
            company: $project->company,
            service: ServiceType::Video,
            rawCostToPingly: $rawCost,
            billedAmountToClient: $billed,
            multiplierOrMargin: $multiplier,
            metadata: [
                'model' => $project->model,
                'project_id' => $project->id,
                'shot' => $shot->position + 1,
                'seconds' => $shot->duration,
                'resolution' => $project->resolution,
            ],
            cap: (float) config('pingly.video.max_billed_per_shot'),
        );

        $shot->update(['raw_cost' => $rawCost, 'billed_amount' => $event->billed_amount_to_client, 'usage_event_id' => $event->id]);
        $project->increment('billed_total', (float) $event->billed_amount_to_client);
    }

    private function retryOrFail(VideoProject $project, VideoShot $shot, string $reason): void
    {
        $contentFilter = str_contains(strtolower($reason), 'policy') || str_contains(strtolower($reason), 'safety') || str_contains(strtolower($reason), 'moderation');

        if (! $contentFilter && $shot->attempts < (int) config('pingly.video.max_attempts')) {
            $shot->update(['status' => 'pending', 'provider_job_id' => null, 'error' => $reason]);

            return;
        }

        $shot->update(['status' => 'failed', 'error' => $reason]);
        $this->fail($project, $contentFilter
            ? 'Shot '.($shot->position + 1).' was blocked by the content filter — edit it and resume.'
            : 'Shot '.($shot->position + 1).' failed to render after several tries — resume to try again. You were only charged for finished shots.');
    }

    private function maybeFinishRendering(VideoProject $project): void
    {
        $project->refresh();
        if ($project->status === 'rendering' && $project->shots()->where('status', '!=', 'completed')->doesntExist()) {
            $project->update(['status' => 'stitching']);
        }
    }

    private function finish(VideoProject $project): void
    {
        $shots = $project->shots()->get();
        $disk = Storage::disk('local');
        $output = $project->storageDir().'/film.mp4';

        if ($shots->count() === 1) {
            $disk->copy($shots[0]->file_path, $output);
        } else {
            [$w, $h] = $this->catalog->size($project->aspect_ratio, $project->resolution);
            $this->stitcher->stitch($shots->map(fn ($s) => $disk->path($s->file_path))->all(), $disk->path($output), $w, $h);
        }

        $thumbnail = null;
        try {
            $thumbnail = $project->storageDir().'/poster.jpg';
            $this->stitcher->still($disk->path($output), $disk->path($thumbnail), min(1.5, $shots->sum('duration') / 2));
        } catch (Throwable) {
            $thumbnail = null; // a missing poster isn't worth failing a finished film over
        }

        $project->update([
            'status' => 'completed',
            'output_path' => $output,
            'thumbnail_path' => $thumbnail,
            'output_duration' => (int) round($this->stitcher->available() ? ($this->stitcher->duration($disk->path($output)) ?? $shots->sum('duration')) : $shots->sum('duration')),
            'completed_at' => now(),
        ]);
    }

    private function fail(VideoProject $project, string $message): void
    {
        $project->update(['status' => 'failed', 'error' => $message]);
        // Shots still in flight will finish upstream and be paid for by
        // Pingly; put them back so a resume re-renders rather than waits.
        $project->shots()->where('status', 'submitted')->update(['status' => 'pending', 'provider_job_id' => null]);
    }

    // ─── housekeeping ─────────────────────────────────────────────────

    public function deleteProject(VideoProject $project): void
    {
        if ($project->isActive()) {
            throw new RuntimeException('Wait for this video to finish before deleting it.');
        }

        Storage::disk('local')->deleteDirectory($project->storageDir());
        $project->delete();
    }

    /**
     * @param  array<int, array{data: string, label: string, role: string}>  $references
     * @return array<int, array{path: string, label: string, role: string}>
     */
    private function storeReferences(VideoProject $project, array $references): array
    {
        $stored = [];
        foreach (array_values($references) as $i => $ref) {
            if (! preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#', $ref['data'], $m)) {
                throw new RuntimeException('Reference images must be JPEG, PNG or WebP.');
            }
            $bytes = base64_decode($m[2], true);
            if ($bytes === false || strlen($bytes) > 8 * 1024 * 1024 || @getimagesizefromstring($bytes) === false) {
                throw new RuntimeException('One of the reference images is invalid or larger than 8 MB.');
            }

            $path = $project->storageDir().sprintf('/ref_%02d.%s', $i + 1, $m[1] === 'jpeg' ? 'jpg' : $m[1]);
            Storage::disk('local')->put($path, $bytes);
            $stored[] = ['path' => $path, 'label' => Str::limit(trim($ref['label']), 60, ''), 'role' => $ref['role'], 'real_person' => ! empty($ref['real_person'])];
        }

        return $stored;
    }

    private function dataUrl(string $path): string
    {
        $disk = Storage::disk('local');
        $mime = $disk->mimeType($path) ?: 'image/jpeg';

        return "data:{$mime};base64,".base64_encode($disk->get($path));
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::withToken((string) $this->ai->apiKey())->timeout($timeout);
    }

    private function assertUsable(Company $company): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Video Studio is not configured yet — contact support.');
        }
        if (! $this->isEnabledFor($company)) {
            throw new RuntimeException('Video Studio is not enabled for this account.');
        }
    }
}
