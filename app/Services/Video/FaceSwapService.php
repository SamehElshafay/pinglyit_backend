<?php

namespace App\Services\Video;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\VideoFaceSwap;
use App\Models\VideoProject;
use App\Services\Billing\BillingEngine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Face swap: put a real, consenting person's face onto the actor of a
 * finished film — the "brand owner stars in the ad" effect. Generation
 * models (Seedance) refuse real faces, but a film made with an AI actor
 * can have that actor's face swapped afterwards.
 *
 * Provider: PiAPI's video face swap (Qubico/video-toolkit, face-swap):
 * $0.004 per frame, MP4 ≤ 10 MB, ≤ 720p, ≤ 600 frames. The film is
 * re-encoded to fit; PiAPI fetches it and the face photo from short-lived
 * signed URLs. Billed only when the swapped video is delivered.
 */
class FaceSwapService
{
    public const PER_FRAME = 0.004;

    public const FPS = 24;

    public const MAX_SECONDS = 25; // 600 frames at 24 fps

    public const MODEL_KEY = 'piapi/face-swap';

    public function __construct(
        private readonly VideoStitcher $stitcher,
        private readonly BillingEngine $billing,
        private readonly VideoStudioService $studio,
    ) {}

    public function apiKey(): ?string
    {
        return PlatformSetting::get('piapi_api_key');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    /** What swapping this film's faces will cost the client. */
    public function quote(VideoProject $project): ?array
    {
        $seconds = min((float) ($project->output_duration ?: $project->shots()->sum('duration')), self::MAX_SECONDS);
        if ($seconds <= 0) {
            return null;
        }
        $frames = (int) ceil($seconds * self::FPS);
        $raw = round($frames * self::PER_FRAME, 6);

        return ['frames' => $frames, 'seconds' => $seconds, 'raw' => $raw,
            'price' => round($raw * $this->studio->multiplierFor($project->company, self::MODEL_KEY), 4)];
    }

    public function start(VideoProject $project, User $user, string $faceDataUrl, int $targetFaceIndex = 0): VideoFaceSwap
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Face swap is not set up yet — contact support.');
        }
        if ($project->status !== 'completed' || ! $project->output_path) {
            throw new RuntimeException('Finish the video first, then swap a face into it.');
        }
        if (! $this->stitcher->available()) {
            throw new RuntimeException('Face swap is temporarily unavailable — contact support.');
        }
        if ($project->faceSwaps()->whereIn('status', ['pending', 'processing'])->exists()) {
            throw new RuntimeException('A face swap on this video is already running.');
        }
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#', $faceDataUrl, $m)) {
            throw new RuntimeException('The face photo must be a JPEG, PNG or WebP.');
        }
        $bytes = base64_decode($m[2], true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($size === false || strlen($bytes) > 8 * 1024 * 1024 || $size[0] > 2048 || $size[1] > 2048) {
            throw new RuntimeException('The face photo is invalid, over 8 MB, or larger than 2048 × 2048.');
        }

        $quote = $this->quote($project);
        $company = $project->company;
        if (! $this->billing->hasSufficientBalance($company, $quote['price'])) {
            throw new RuntimeException('Not enough balance — the face swap costs $'.number_format($quote['price'], 2).'. Top up and try again.');
        }

        $swap = VideoFaceSwap::create([
            'video_project_id' => $project->id, 'company_id' => $company->id, 'user_id' => $user->id,
            'face_path' => '', 'target_face_index' => $targetFaceIndex, 'status' => 'pending',
            'frames' => $quote['frames'], 'quoted_amount' => $quote['price'], 'consented_at' => now(),
        ]);

        $disk = Storage::disk('local');
        $dir = $project->storageDir()."/faceswap_{$swap->id}";
        $face = "{$dir}/face.".($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        $source = "{$dir}/source.mp4";
        $disk->put($face, $bytes);

        try {
            @mkdir(dirname($disk->path($source)), 0775, true);
            $this->stitcher->fitForFaceSwap($disk->path($project->output_path), $disk->path($source), self::MAX_SECONDS);
            if (filesize($disk->path($source)) > 10 * 1024 * 1024) {
                throw new RuntimeException('This video is too large for face swap.');
            }
        } catch (Throwable $e) {
            $swap->update(['face_path' => $face, 'status' => 'failed', 'error' => 'Could not prepare the video.']);
            Log::error('Face swap: prepare failed', ['swap' => $swap->id, 'error' => $e->getMessage()]);

            throw new RuntimeException('Could not prepare the video for face swap — try again.');
        }

        $swap->update(['face_path' => $face, 'source_path' => $source]);
        $this->submit($swap);

        return $swap->fresh();
    }

    private function submit(VideoFaceSwap $swap): void
    {
        $signed = fn (string $kind) => URL::temporarySignedRoute('faceswap.media', now()->addHours(3), ['swap' => $swap->id, 'kind' => $kind]);

        try {
            $response = Http::withHeaders(['x-api-key' => (string) $this->apiKey()])->timeout(60)
                ->post(config('pingly.video.faceswap_api_base').'/task', [
                    'model' => 'Qubico/video-toolkit',
                    'task_type' => 'face-swap',
                    'input' => [
                        'swap_image' => $signed('face'),
                        'target_video' => $signed('source'),
                        'swap_faces_index' => '0',
                        'target_faces_index' => (string) $swap->target_face_index,
                    ],
                ]);
        } catch (ConnectionException) {
            $swap->update(['status' => 'failed', 'error' => "Couldn't reach the face swap service — try again shortly."]);

            return;
        }

        $taskId = $response->json('data.task_id');
        if ($response->failed() || ! $taskId) {
            Log::error('Face swap: submit rejected', ['swap' => $swap->id, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 1000)]);
            $swap->update(['status' => 'failed', 'error' => 'The face swap service rejected this video. Nothing was charged.']);

            return;
        }

        $swap->update(['status' => 'processing', 'task_id' => $taskId, 'last_polled_at' => now()]);
    }

    public function advanceAll(): int
    {
        $swaps = VideoFaceSwap::where('status', 'processing')
            ->where(fn ($q) => $q->whereNull('last_polled_at')->orWhere('last_polled_at', '<=', now()->subSeconds(20)))
            ->limit(20)->get();

        foreach ($swaps as $swap) {
            $this->advance($swap);
        }

        return $swaps->count();
    }

    public function advance(VideoFaceSwap $swap): void
    {
        $lock = Cache::lock("face-swap:{$swap->id}", 300);
        if (! $lock->get()) {
            return;
        }

        try {
            $swap->refresh();
            if ($swap->status !== 'processing') {
                return;
            }
            $swap->update(['last_polled_at' => now()]);

            try {
                $response = Http::withHeaders(['x-api-key' => (string) $this->apiKey()])->timeout(30)
                    ->get(config('pingly.video.faceswap_api_base')."/task/{$swap->task_id}");
            } catch (ConnectionException) {
                return; // try again next tick
            }
            if ($response->failed()) {
                return;
            }

            $status = strtolower((string) $response->json('data.status'));
            if ($status === 'completed') {
                $this->collect($swap, (string) $response->json('data.output.video_url'));
            } elseif ($status === 'failed') {
                Log::warning('Face swap failed upstream', ['swap' => $swap->id, 'error' => $response->json('data.error')]);
                $swap->update(['status' => 'failed', 'error' => 'The face swap failed — usually no clear face was found in the photo or the video. Nothing was charged.']);
            } elseif ($swap->created_at->lt(now()->subMinutes(45))) {
                $swap->update(['status' => 'failed', 'error' => 'The face swap took too long and was stopped. Nothing was charged.']);
            }
        } finally {
            $lock->release();
        }
    }

    private function collect(VideoFaceSwap $swap, string $url): void
    {
        $disk = Storage::disk('local');
        $path = dirname($swap->source_path).'/swapped.mp4';

        if ($url === '') {
            return;
        }
        $download = Http::timeout(300)->sink($disk->path($path))->get($url);
        if ($download->failed() || filesize($disk->path($path)) < 1024) {
            Log::warning('Face swap: download failed', ['swap' => $swap->id, 'status' => $download->status()]);

            return; // next poll retries
        }

        DB::transaction(function () use ($swap, $path) {
            $swap = VideoFaceSwap::lockForUpdate()->find($swap->id);
            if ($swap->status !== 'processing') {
                return;
            }
            $raw = round($swap->frames * self::PER_FRAME, 6);
            $company = $swap->company;
            $multiplier = $this->studio->multiplierFor($company, self::MODEL_KEY);

            $event = $this->billing->recordUsage(
                company: $company,
                service: ServiceType::Video,
                rawCostToPingly: $raw,
                billedAmountToClient: min(round($raw * $multiplier, 6), (float) $swap->quoted_amount),
                multiplierOrMargin: $multiplier,
                metadata: ['model' => self::MODEL_KEY, 'project_id' => $swap->video_project_id, 'face_swap_id' => $swap->id, 'frames' => $swap->frames],
                cap: (float) config('pingly.video.max_billed_per_shot'),
            );

            $swap->update(['status' => 'completed', 'output_path' => $path, 'raw_cost' => $raw,
                'billed_amount' => $event->billed_amount_to_client, 'usage_event_id' => $event->id, 'error' => null]);
        });
    }

    /** @return array<string, mixed> */
    public function present(VideoFaceSwap $swap): array
    {
        return [
            'id' => $swap->id,
            'status' => $swap->status,
            'price' => (float) ($swap->billed_amount ?? $swap->quoted_amount),
            'error' => $swap->error,
            'created_at' => $swap->created_at,
            'video_url' => $swap->output_path ? URL::temporarySignedRoute('faceswap.media', now()->addHours(6), ['swap' => $swap->id, 'kind' => 'result']) : null,
            'download_url' => $swap->output_path ? URL::temporarySignedRoute('faceswap.media', now()->addHours(6), ['swap' => $swap->id, 'kind' => 'result', 'download' => 1]) : null,
        ];
    }

    public function companyCanUse(Company $company): bool
    {
        return $this->isConfigured() && $this->studio->isEnabledFor($company);
    }
}
