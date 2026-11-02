<?php

namespace App\Services\Video;

use App\Services\Ai\AiGatewayService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The video models Pingly offers, and what a shot on each one costs.
 *
 * Curated, not the whole OpenRouter catalog: every model here accepts
 * reference images (`input_references`), which is what keeps a face, a
 * product and a wardrobe the same from one shot to the next. A model that
 * can only be steered by text drifts — the same "Omar" comes out as a
 * different man every cut — and that's the one failure a brand film can't
 * survive, so those models aren't offered at all.
 *
 * Checked against GET /api/v1/videos/models on 2026-11-02 (31 models listed;
 * edit/upscale tools and text-only models left out).
 * `fallback_per_second` is only used if that live price lookup fails, so an
 * estimate is never $0; the client is always billed from what OpenRouter
 * reports it actually charged (see VideoStudioService::billShot()).
 */
class VideoModelCatalog
{
    /** @var array<string, array<string, mixed>> */
    private const MODELS = [
        'bytedance/seedance-2.0' => [
            'name' => 'Seedance 2.0',
            'tagline' => 'Best all-round — strongest face & product consistency, native audio',
            'durations' => [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            'shot_length' => 8,
            'resolutions' => ['480p', '720p', '1080p'],
            'aspect_ratios' => ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'],
            'audio' => true,
            'max_references' => 9,
            'fallback_per_second' => 0.37,
            'recommended' => true,
            'blocks_real_faces' => true,
        ],
        'bytedance/seedance-2.5' => [
            'name' => 'Seedance 2.5',
            'tagline' => 'Long takes up to 30s per shot — built for storytelling',
            'durations' => [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30],
            'shot_length' => 10,
            'resolutions' => ['480p', '720p'],
            'aspect_ratios' => ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'],
            'audio' => true,
            'max_references' => 9,
            'fallback_per_second' => 0.24,
            'blocks_real_faces' => true,
        ],
        'google/veo-3.1' => [
            'name' => 'Veo 3.1',
            'tagline' => 'Premium realism and lip-synced dialogue — highest cost',
            'durations' => [4, 6, 8],
            'shot_length' => 8,
            'resolutions' => ['720p', '1080p'],
            'aspect_ratios' => ['16:9', '9:16'],
            'audio' => true,
            'max_references' => 3,
            'fallback_per_second' => 0.40,
        ],
        'alibaba/wan-3.0' => [
            'name' => 'Wan 3.0',
            'tagline' => 'Budget 1080p with audio — good for drafts and social cuts',
            'durations' => [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30],
            'shot_length' => 8,
            'resolutions' => ['480p', '720p', '1080p'],
            'aspect_ratios' => ['16:9', '9:16', '1:1', '4:3', '3:4'],
            'audio' => true,
            'max_references' => 4,
            'fallback_per_second' => 0.20,
        ],
        'bytedance/seedance-2.0-fast' => [
            'name' => 'Seedance 2.0 Fast',
            'tagline' => 'Quick, cheap previews of a storyboard before the final render',
            'durations' => [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            'shot_length' => 8,
            'resolutions' => ['480p', '720p'],
            'aspect_ratios' => ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'],
            'audio' => true,
            'max_references' => 9,
            'fallback_per_second' => 0.10,
            'blocks_real_faces' => true,
        ],
        'bytedance/seedance-2.0-mini' => [
            'name' => 'Seedance 2.0 Mini',
            'tagline' => 'The cheapest Seedance — same consistency, for small budgets',
            'durations' => [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            'shot_length' => 8,
            'resolutions' => ['480p', '720p'],
            'aspect_ratios' => ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'],
            'audio' => true,
            'max_references' => 9,
            'fallback_per_second' => 0.08,
            'blocks_real_faces' => true,
        ],
        // Kling takes no reference images, only a first frame. So each shot
        // starts from a keyframe that Pingly first paints with an image model
        // (from the client's photos) — which is also what lets a real
        // person's face through: Seedance refuses those.
        'kwaivgi/kling-v3.0-pro' => [
            'name' => 'Kling 3.0 Pro',
            'tagline' => 'Real people — keeps the face from your photo in every shot',
            'durations' => [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            'shot_length' => 8,
            'resolutions' => ['720p'],
            'aspect_ratios' => ['16:9', '9:16', '1:1'],
            'audio' => true,
            'max_references' => 4,
            'fallback_per_second' => 0.168,
            'conditioning' => 'keyframe',
        ],
        'alibaba/happyhorse-1.1' => [
            'name' => 'HappyHorse 1.1',
            'tagline' => 'Sharp 1080p from several reference images — good value, no audio',
            'durations' => [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            'shot_length' => 8,
            'resolutions' => ['720p', '1080p'],
            'aspect_ratios' => ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'],
            'audio' => false,
            'max_references' => 4,
            'fallback_per_second' => 0.13,
        ],
    ];

    /** Where a real person's photo goes when the chosen model refuses real faces. */
    public const REAL_FACE_MODEL = 'kwaivgi/kling-v3.0-pro';

    /** Paints each shot's first frame for keyframe-conditioned models. */
    public const KEYFRAME_IMAGE_MODEL = 'google/gemini-3-pro-image';

    /** What one keyframe image costs, for the quote (~$0.13 measured on Gemini 3 Pro Image). */
    public const KEYFRAME_ESTIMATE = 0.14;

    /** Pixel sizes per aspect ratio + resolution — what a token-priced model bills on. */
    private const SIZES = [
        '16:9' => ['480p' => [854, 480], '720p' => [1280, 720], '1080p' => [1920, 1080], '4K' => [3840, 2160]],
        '9:16' => ['480p' => [480, 854], '720p' => [720, 1280], '1080p' => [1080, 1920], '4K' => [2160, 3840]],
        '1:1' => ['480p' => [480, 480], '720p' => [720, 720], '1080p' => [1080, 1080]],
        '4:3' => ['480p' => [640, 480], '720p' => [960, 720], '1080p' => [1440, 1080]],
        '3:4' => ['480p' => [480, 640], '720p' => [720, 960], '1080p' => [1080, 1440]],
        '21:9' => ['480p' => [1120, 480], '720p' => [1680, 720], '1080p' => [2520, 1080]],
    ];

    public function __construct(private readonly AiGatewayService $ai) {}

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::MODELS;
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return self::MODELS[$id] ?? null;
    }

    /** @return array{0: int, 1: int} */
    public function size(string $aspectRatio, string $resolution): array
    {
        return self::SIZES[$aspectRatio][$resolution] ?? [1280, 720];
    }

    /**
     * Split a film's length into shots the model can render.
     *
     * Shots land near the model's natural `shot_length` (8s is long enough
     * for an action to read and short enough that the model doesn't wander),
     * spread evenly, then snapped to a length the model actually supports.
     * The total can come out a second or two off the target when a model
     * only offers coarse lengths (Veo: 4/6/8) — the UI shows the real total.
     *
     * @return array<int, int>
     */
    public function planShotDurations(string $modelId, int $target): array
    {
        $model = $this->get($modelId);
        $allowed = $model['durations'];
        $max = max($allowed);

        if ($target <= $max) {
            return [$this->snap($target, $allowed)];
        }

        $count = (int) ceil($target / $model['shot_length']);
        $base = intdiv($target, $count);
        $extra = $target % $count;

        $durations = [];
        for ($i = 0; $i < $count; $i++) {
            $durations[] = $this->snap($base + ($i < $extra ? 1 : 0), $allowed);
        }

        return $durations;
    }

    /** @param array<int, int> $allowed */
    private function snap(int $seconds, array $allowed): int
    {
        return collect($allowed)->sortBy(fn ($d) => [abs($d - $seconds), -$d])->first();
    }

    /**
     * What OpenRouter will charge Pingly for one shot — an estimate for the
     * quote shown before rendering, not what's billed (that comes from the
     * provider's own usage.cost once the shot is done).
     */
    public function estimateShotCost(string $modelId, string $resolution, string $aspectRatio, int $seconds, bool $audio, int $referenceCount): float
    {
        $skus = $this->pricingSkus($modelId);
        $perSecond = $skus ? $this->perSecond($skus, $resolution, $aspectRatio, $audio) : null;
        $perSecond ??= (float) ($this->get($modelId)['fallback_per_second'] ?? 0.4);

        $referenceFee = 0.0;
        foreach (['reference_images', 'reference_input_images'] as $key) {
            if (isset($skus[$key])) {
                $referenceFee = (float) $skus[$key];
            }
        }
        if (isset($skus['cents_per_image_input'])) {
            $referenceFee = (float) $skus['cents_per_image_input'] / 100;
        }

        if (($this->get($modelId)['conditioning'] ?? null) === 'keyframe') {
            return round($perSecond * $seconds + self::KEYFRAME_ESTIMATE, 6);
        }

        return round($perSecond * $seconds + $referenceFee * $referenceCount, 6);
    }

    /**
     * OpenRouter publishes each video model's price in its own unit — some
     * per output second (in dollars or cents, often per resolution and per
     * audio on/off), ByteDance's per "video token" (w × h × fps / 1024 per
     * second). This turns whichever one the model uses into $/second.
     *
     * @param  array<string, string>  $skus
     */
    private function perSecond(array $skus, string $resolution, string $aspectRatio, bool $audio): ?float
    {
        $res = strtolower($resolution);
        $audioWord = $audio ? 'with' : 'without';

        if (collect($skus)->keys()->contains(fn ($k) => str_starts_with($k, 'video_tokens'))) {
            [$w, $h] = $this->size($aspectRatio, $resolution);
            $price = $skus["video_tokens_{$res}"] ?? (! $audio ? ($skus['video_tokens_without_audio'] ?? null) : null) ?? $skus['video_tokens'] ?? null;

            return $price === null ? null : ($w * $h * 24 / 1024) * (float) $price;
        }

        foreach ([
            "duration_seconds_{$audioWord}_audio_{$res}",
            "duration_seconds_{$audioWord}_audio",
            "duration_seconds_{$res}",
            "text_to_video_duration_seconds_{$res}",
            'duration_seconds',
        ] as $key) {
            if (isset($skus[$key])) {
                return (float) $skus[$key];
            }
        }

        foreach ([
            "cents_per_video_output_second_{$res}",
            "cents_per_second_output_{$res}",
            'cents_per_second_output',
        ] as $key) {
            if (isset($skus[$key])) {
                return (float) $skus[$key] / 100;
            }
        }

        return null;
    }

    /** @return array<string, string>|null */
    private function pricingSkus(string $modelId): ?array
    {
        $all = Cache::get('openrouter_video_pricing');

        if ($all === null) {
            try {
                $response = Http::withToken((string) $this->ai->apiKey())
                    ->timeout(20)
                    ->get(config('pingly.ai.openrouter_api_base').'/videos/models');
            } catch (ConnectionException $e) {
                Log::warning('OpenRouter video pricing lookup failed', ['error' => $e->getMessage()]);

                return null; // not cached — the next quote tries again
            }

            $all = collect($response->json('data', []))
                ->mapWithKeys(fn ($m) => [$m['id'] => $m['pricing_skus'] ?? []])
                ->all();

            if ($all) {
                Cache::put('openrouter_video_pricing', $all, 3600);
            }
        }

        return $all[$modelId] ?? null;
    }
}
