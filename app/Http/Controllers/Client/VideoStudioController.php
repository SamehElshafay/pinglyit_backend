<?php

namespace App\Http\Controllers\Client;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\SocialPost;
use App\Models\VideoProject;
use App\Models\VideoShot;
use App\Services\Billing\BillingEngine;
use App\Services\Meta\MetaPublisher;
use App\Services\Video\VideoDirector;
use App\Services\Video\VideoStudioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Video Studio, dashboard side. See VideoStudioService for the flow
 * (plan → approve → render) and why it's split that way.
 *
 * Nothing here exposes the raw render cost, the margin, or the upstream:
 * prices are the client's own prices, and media is served from Pingly's
 * storage through short-lived signed links, never the provider's URLs.
 */
class VideoStudioController extends Controller
{
    public function __construct(private readonly VideoStudioService $studio) {}

    public function index(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            'enabled' => $this->studio->isEnabledFor($company),
            'balance' => (float) ($company->wallet->balance ?? 0),
            'reserved' => app(BillingEngine::class)->reservedFor($company),
            'models' => $this->studio->availableModelsFor($company),
            'languages' => collect(VideoDirector::LANGUAGE_NAMES)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'styles' => collect(VideoDirector::STYLES)->map(fn ($v, $k) => ['id' => $k, 'name' => trim(strtok($v, ':'))])->values(),
            'max_duration' => (int) config('pingly.video.max_duration'),
            'projects' => $company->videoProjects()->with('shots')->latest('id')->limit(30)->get()
                ->map(fn ($p) => $this->present($p, false)),
            'usage' => $company->usageEvents()->where('service_type', ServiceType::Video)->latest()->limit(25)->get()
                ->map(fn ($e) => [
                    'time' => $e->created_at,
                    'project_id' => $e->metadata['project_id'] ?? null,
                    'shot' => $e->metadata['shot'] ?? null,
                    'seconds' => $e->metadata['seconds'] ?? null,
                    'amount' => (float) $e->billed_amount_to_client,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:10', 'max:4000'],
            'model' => ['required', 'string'],
            'resolution' => ['required', 'string'],
            'aspect_ratio' => ['required', 'string'],
            'duration' => ['required', 'integer', 'min:4', 'max:'.config('pingly.video.max_duration')],
            'generate_audio' => ['boolean'],
            'language' => ['nullable', Rule::in(array_keys(VideoDirector::LANGUAGES))],
            'style' => ['nullable', Rule::in(array_keys(VideoDirector::STYLES))],
            'references' => ['array', 'max:9'],
            'references.*.data' => ['required', 'string'],
            'references.*.label' => ['required', 'string', 'max:60'],
            'references.*.role' => ['required', Rule::in(['character', 'product', 'logo', 'location', 'style'])],
            'references.*.real_person' => ['boolean'],
            // A real person's face is only used with their permission.
            'real_person_consent' => Rule::when(
                collect($request->input('references', []))->contains(fn ($r) => ! empty($r['real_person'])),
                ['accepted'],
            ),
        ], ['real_person_consent.accepted' => 'Confirm you have permission from the person in the photo.']);

        // Planning is one LLM round trip that can take ~30s on a long film.
        @set_time_limit(180);

        try {
            $project = $this->studio->createDraft(
                $request->user()->company,
                $request->user(),
                $data + ['generate_audio' => true],
                $data['references'] ?? [],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($project), 201);
    }

    public function show(Request $request, int $project)
    {
        $project = $this->find($request, $project);

        // If the scheduler isn't running (or is between ticks), a client
        // watching their render still moves it forward — after the
        // response is sent, so polling stays fast. Lock-guarded inside.
        if ($project->isActive()) {
            dispatch(fn () => app(VideoStudioService::class)->advance($project))->afterResponse();
        }
        if (SocialPost::where('video_project_id', $project->id)->where('status', 'processing')->exists()) {
            dispatch(fn () => app(MetaPublisher::class)->advance())->afterResponse();
        }

        return response()->json($this->present($project));
    }

    public function updateShot(Request $request, int $project, int $shot)
    {
        $project = $this->find($request, $project);
        $shot = $project->shots()->whereKey($shot)->firstOrFail();
        $data = $request->validate(['description' => ['required', 'string', 'min:10', 'max:2000']]);

        try {
            $this->studio->updateShot($project, $shot, $data['description']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($project->fresh('shots')));
    }

    public function render(Request $request, int $project)
    {
        $project = $this->find($request, $project);
        @set_time_limit(180);

        try {
            $project = $this->studio->start($project);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($project));
    }

    /** A ready-to-post caption for this film, written by the AI (billed as AI usage). */
    public function caption(Request $request, int $project)
    {
        $project = $this->find($request, $project);
        $data = $request->validate([
            'language' => ['nullable', Rule::in(array_keys(VideoDirector::LANGUAGES))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $caption = app(MetaPublisher::class)->writeCaption(
                $request->user()->company, $project, $data['language'] ?? ($project->language ?: 'ar-EG'), $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['caption' => $caption]);
    }

    /** Post the finished film to the client's Facebook Page and/or Instagram. */
    public function publish(Request $request, int $project)
    {
        $project = $this->find($request, $project);
        $data = $request->validate([
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['distinct', Rule::in(MetaPublisher::PLATFORMS)],
            'caption' => ['nullable', 'string', 'max:2200'], // Instagram's caption limit
        ]);

        try {
            $posts = app(MetaPublisher::class)->publish($project, $data['platforms'], (string) ($data['caption'] ?? ''));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'posts' => collect($posts)->map(fn (SocialPost $p) => $this->presentPost($p)),
            'project' => $this->present($project->fresh('shots')),
        ]);
    }

    public function destroy(Request $request, int $project)
    {
        try {
            $this->studio->deleteProject($this->find($request, $project));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->noContent();
    }

    /**
     * Signed, no JWT: a <video> tag can't send an Authorization header.
     * The signature (6-hour expiry) is the authorisation — it's only ever
     * minted by present() for the project's own company.
     */
    public function media(Request $request, VideoProject $project, string $kind, int $index = 0)
    {
        $path = match ($kind) {
            'film' => $project->output_path,
            'poster' => $project->thumbnail_path,
            'shot' => $project->shots()->where('position', $index)->value('file_path'),
            'reference' => $project->references[$index]['path'] ?? null,
            default => null,
        };

        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $absolute = Storage::disk('local')->path($path);

        if ($request->boolean('download')) {
            $name = str($project->title ?: 'pingly-video')->slug()->append($kind === 'shot' ? '-shot-'.($index + 1) : '')->append('.mp4');

            return response()->download($absolute, (string) $name);
        }

        return response()->file($absolute, ['Cache-Control' => 'private, max-age=21600']);
    }

    private function find(Request $request, int $id): VideoProject
    {
        return $request->user()->company->videoProjects()->with('shots')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function presentPost(SocialPost $p): array
    {
        return $p->only(['id', 'platform', 'status', 'permalink', 'error', 'published_at', 'created_at', 'caption']);
    }

    /** @return array<string, mixed> */
    private function present(VideoProject $project, bool $full = true): array
    {
        $url = fn (string $kind, int $index = 0, bool $download = false) => URL::temporarySignedRoute(
            'video.media', now()->addHours(6),
            array_filter(['project' => $project->id, 'kind' => $kind, 'index' => $index, 'download' => $download ? 1 : null], fn ($v) => $v !== null),
        );

        $shots = $project->shots;
        $board = $project->storyboard ?? [];
        $done = $shots->where('status', 'completed')->count();

        $data = [
            'id' => $project->id,
            'title' => $project->title,
            'prompt' => $project->prompt,
            'status' => $project->status,
            'error' => $project->error,
            'notice' => $board['notice'] ?? null,
            'model' => $project->model,
            'resolution' => $project->resolution,
            'aspect_ratio' => $project->aspect_ratio,
            'target_duration' => $project->target_duration,
            'total_duration' => (int) $shots->sum('duration'),
            'output_duration' => $project->output_duration,
            'language' => $project->language,
            'style' => $project->style,
            'generate_audio' => $project->generate_audio,
            'estimated_cost' => (float) $project->estimated_cost,
            'remaining_cost' => (float) $shots->where('status', '!=', 'completed')->sum('quoted_amount'),
            'billed_total' => (float) $project->billed_total,
            'progress' => ['done' => $done, 'total' => $shots->count()],
            'poster_url' => $project->thumbnail_path ? $url('poster') : null,
            'film_url' => $project->output_path ? $url('film') : null,
            'download_url' => $project->output_path ? $url('film', 0, true) : null,
            'created_at' => $project->created_at,
            'completed_at' => $project->completed_at,
        ];

        if (! $full) {
            return $data;
        }

        $publisher = app(MetaPublisher::class);
        $planned = $board['shots'] ?? [];
        $names = collect($board['characters'] ?? [])->merge($board['products'] ?? [])->pluck('name', 'id');

        return $data + [
            'publishing' => [
                'targets' => $publisher->targets($project->company->metaConnection),
                'posts' => SocialPost::where('video_project_id', $project->id)->latest('id')->get()->map(fn ($p) => $this->presentPost($p)),
            ],
            'logline' => $board['logline'] ?? null,
            'style_bible' => $board['style_bible'] ?? null,
            'characters' => $board['characters'] ?? [],
            'products' => $board['products'] ?? [],
            'references' => collect($project->references ?? [])->map(fn ($r, $i) => [
                'label' => $r['label'], 'role' => $r['role'], 'real_person' => $r['real_person'] ?? false, 'url' => $url('reference', $i),
            ])->values(),
            'shots' => $shots->map(fn (VideoShot $s) => [
                'id' => $s->id,
                'position' => $s->position,
                'duration' => $s->duration,
                'description' => $s->description,
                'status' => $s->status,
                'price' => (float) ($s->billed_amount ?? $s->quoted_amount),
                'cast' => collect([...($planned[$s->position]['characters'] ?? []), ...($planned[$s->position]['products'] ?? [])])
                    ->map(fn ($id) => $names[$id] ?? null)->filter()->values(),
                'dialogue' => collect($planned[$s->position]['dialogue'] ?? [])->map(fn ($l) => [
                    'speaker' => $names[$l['speaker']] ?? $l['speaker'], 'line' => $l['line'],
                ]),
                'voiceover' => $planned[$s->position]['voiceover'] ?? null,
                'sound' => $planned[$s->position]['sound'] ?? null,
                'video_url' => $s->file_path ? $url('shot', $s->position) : null,
            ])->values(),
        ];
    }
}
