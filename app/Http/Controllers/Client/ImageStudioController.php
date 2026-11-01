<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\AiImage;
use App\Models\SocialPost;
use App\Services\Ai\AiGatewayService;
use App\Services\Image\ImageStudioService;
use App\Services\Meta\MetaPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Image Studio, dashboard side: create ad images, write a caption, post
 * them to the client's Facebook Page / Instagram.
 */
class ImageStudioController extends Controller
{
    public function __construct(
        private readonly ImageStudioService $images,
        private readonly MetaPublisher $publisher,
    ) {}

    public function index(Request $request)
    {
        $company = $request->user()->company;
        $images = AiImage::where('company_id', $company->id)->whereNotNull('path')->latest('id')->limit(60)->get();
        $posts = SocialPost::whereIn('ai_image_id', $images->pluck('id'))->latest('id')->get()->groupBy('ai_image_id');

        return response()->json([
            'models' => collect(ImageStudioService::MODELS)->map(fn ($m, $id) => ['id' => $id] + $m)->values(),
            'aspect_ratios' => ImageStudioService::ASPECT_RATIOS,
            'targets' => $this->publisher->targets($company->metaConnection),
            'images' => $images->map(fn (AiImage $i) => $this->present($i, $posts->get($i->id, collect()))),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:5', 'max:3000'],
            'model' => ['required', Rule::in(array_keys(ImageStudioService::MODELS))],
            'aspect_ratio' => ['required', Rule::in(ImageStudioService::ASPECT_RATIOS)],
            'references' => ['array', 'max:4'],
            'references.*' => ['string', 'regex:#^data:image/(png|jpeg|webp);base64,#', 'max:11000000'],
        ]);
        @set_time_limit(200);

        try {
            $image = $this->images->generate($request->user()->company, $data['prompt'], $data['model'], $data['aspect_ratio'], $data['references'] ?? []);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($image, collect()), 201);
    }

    public function destroy(Request $request, int $image)
    {
        $this->images->delete($this->find($request, $image));

        return response()->noContent();
    }

    public function caption(Request $request, int $image)
    {
        $image = $this->find($request, $image);
        $data = $request->validate([
            'language' => ['nullable', 'in:ar-EG,ar-SA,ar,en'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $language = ['ar-EG' => 'Egyptian Arabic', 'ar-SA' => 'Gulf Arabic', 'ar' => 'Modern Standard Arabic', 'en' => 'English'][$data['language'] ?? 'ar-EG'];

        try {
            $res = app(AiGatewayService::class)->forward($request->user()->company, (string) config('pingly.meta.advisor_model'), [
                ['role' => 'system', 'content' => 'You are a social media copywriter for brands in Egypt and the Gulf. Write one caption for a Facebook/Instagram post of this image: a hook line, two or three short selling lines, one call to action, 3-6 relevant hashtags. No unsupported claims, no fake urgency, plain text only. Language: '.$language.'.'],
                ['role' => 'user', 'content' => 'What the image shows (the brief it was made from): '.$image->prompt.(! empty($data['notes']) ? "\nWhat to mention: ".$data['notes'] : '')],
            ], ['max_tokens' => 600]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['caption' => trim((string) ($res['choices'][0]['message']['content'] ?? ''), " \n\"'")]);
    }

    public function publish(Request $request, int $image)
    {
        $image = $this->find($request, $image);
        $data = $request->validate([
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['distinct', Rule::in(MetaPublisher::PLATFORMS)],
            'caption' => ['nullable', 'string', 'max:2200'],
        ]);

        try {
            $this->publisher->publishImage($image, $data['platforms'], (string) ($data['caption'] ?? ''));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($image, SocialPost::where('ai_image_id', $image->id)->latest('id')->get()));
    }

    /** Signed, no JWT — for <img> tags and for Meta to fetch when publishing. */
    public function media(Request $request, AiImage $image)
    {
        abort_unless($image->path && Storage::disk('local')->exists($image->path), 404);

        return $request->boolean('download')
            ? response()->download(Storage::disk('local')->path($image->path), "pingly-image-{$image->id}.".pathinfo($image->path, PATHINFO_EXTENSION))
            : response()->file(Storage::disk('local')->path($image->path), ['Cache-Control' => 'private, max-age=21600']);
    }

    private function find(Request $request, int $id): AiImage
    {
        return AiImage::where('company_id', $request->user()->company_id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(AiImage $i, $posts): array
    {
        return [
            'id' => $i->id,
            'prompt' => $i->prompt,
            'model' => $i->model,
            'aspect_ratio' => $i->aspect_ratio,
            'price' => (float) $i->billed_amount,
            'url' => URL::temporarySignedRoute('image.media', now()->addHours(6), ['image' => $i->id]),
            'download_url' => URL::temporarySignedRoute('image.media', now()->addHours(6), ['image' => $i->id, 'download' => 1]),
            'created_at' => $i->created_at,
            'posts' => collect($posts)->map(fn ($p) => $p->only(['id', 'platform', 'status', 'permalink', 'error', 'published_at'])),
        ];
    }
}
