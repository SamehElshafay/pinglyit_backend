<?php

namespace App\Services\Image;

use App\Models\AiImage;
use App\Models\Company;
use App\Services\Ai\AiGatewayService;
use App\Services\Billing\BillingEngine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Image Studio: ad images from a prompt, optionally built around the
 * client's own product photo, through OpenRouter's image-output models.
 *
 * Billing uses the cost OpenRouter itself reports for the call
 * (usage.include), not a token estimate: image output is priced far above
 * the model's text completion rate, so the usual token × price estimate
 * would undercharge every image — silently, since the call succeeds.
 * Billed as AI Gateway usage at the client's AI multiplier.
 */
class ImageStudioService
{
    /** Checked against OpenRouter's live model list on 2026-11-02. */
    public const MODELS = [
        'google/gemini-3-pro-image' => ['name' => 'Gemini 3 Pro Image', 'tagline' => 'Best quality — keeps your product exactly as it is, handles text in the image', 'recommended' => true],
        'google/gemini-3.1-flash-image' => ['name' => 'Gemini 3.1 Flash Image', 'tagline' => 'Fast and cheap — good for trying ideas'],
        'openai/gpt-5.4-image-2' => ['name' => 'GPT-5.4 Image', 'tagline' => 'A different look — strong at illustration and layouts'],
    ];

    public const ASPECT_RATIOS = ['1:1', '4:5', '9:16', '16:9', '3:4', '4:3'];

    public function __construct(private readonly AiGatewayService $ai) {}

    /**
     * @param  array<int, string>  $references  data: URLs of the client's product/brand photos
     */
    public function generate(Company $company, string $prompt, string $model, string $aspectRatio, array $references): AiImage
    {
        if (! isset(self::MODELS[$model])) {
            throw new RuntimeException('That image model is not available.');
        }
        if (! $this->ai->isConfigured()) {
            throw new RuntimeException('Image Studio is not configured yet — contact support.');
        }
        if (! app(BillingEngine::class)->hasSufficientBalance($company, 0.05)) {
            throw new RuntimeException('Wallet balance is too low — top up to create images.');
        }

        $content = [['type' => 'text', 'text' => $this->instruction($prompt, $aspectRatio, count($references))]];
        foreach ($references as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        try {
            $response = Http::withToken((string) $this->ai->apiKey())->timeout(180)
                ->post(config('pingly.ai.openrouter_api_base').'/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => $content]],
                    'modalities' => ['image', 'text'],
                    'image_config' => ['aspect_ratio' => $aspectRatio],
                    'usage' => ['include' => true],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn't reach the image service — try again shortly.");
        }

        if ($response->failed()) {
            Log::error('Image Studio upstream failed', ['model' => $model, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 1000)]);

            throw new RuntimeException('The image could not be created — try rephrasing, or another model.');
        }

        $dataUrl = $response->json('choices.0.message.images.0.image_url.url');
        if (! is_string($dataUrl) || ! preg_match('#^data:image/(png|jpeg|webp);base64,(.+)$#s', $dataUrl, $m)) {
            // Usually a refusal (the model answered in text instead).
            $text = trim((string) $response->json('choices.0.message.content'));
            Log::warning('Image Studio: no image returned', ['model' => $model, 'text' => mb_substr($text, 0, 300)]);

            throw new RuntimeException('The model didn\'t produce an image for this prompt — it may have been declined. Try rephrasing.');
        }

        $cost = (float) $response->json('usage.cost', 0);
        $this->ai->recordCompletion($company, $model, (int) $response->json('usage.total_tokens', 0), $cost);
        $billed = round($cost * $this->ai->multiplierFor($company, $model), 6);

        $image = AiImage::create([
            'company_id' => $company->id, 'prompt' => $prompt, 'model' => $model,
            'aspect_ratio' => $aspectRatio, 'billed_amount' => $billed,
        ]);
        $path = "images/{$company->id}/{$image->id}.".($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        Storage::disk('local')->put($path, base64_decode($m[2]));
        $image->update(['path' => $path]);

        return $image;
    }

    public function delete(AiImage $image): void
    {
        if ($image->path) {
            Storage::disk('local')->delete($image->path);
        }
        $image->delete();
    }

    private function instruction(string $prompt, string $aspectRatio, int $referenceCount): string
    {
        $refs = $referenceCount
            ? "The attached image(s) show the client's real product/brand. Keep the product exactly as it is — shape, colours, label, logo, proportions. Don't redesign it; place it in the scene described.\n"
            : '';

        return "Create one polished advertising image for social media (aspect ratio {$aspectRatio}).\n"
            .$refs
            ."Brief from the client: {$prompt}\n"
            .'Professional commercial photography or design quality, strong composition with room for the subject to breathe, '
            .'realistic lighting, no watermarks. If the brief asks for text in the image, keep it short and spell it exactly as given; otherwise add no text.';
    }
}
