<?php

namespace App\Services\Meta;

use App\Models\AiImage;
use App\Models\Company;
use App\Models\MetaConnection;
use App\Models\SocialPost;
use App\Models\VideoFaceSwap;
use App\Models\VideoProject;
use App\Services\Ai\AiGatewayService;
use App\Services\Video\VideoDirector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Publishes a finished Video Studio film to the client's own Facebook Page
 * and/or Instagram (as a Reel), through their own Meta connection.
 *
 * Meta pulls the file itself from a URL, so the film is handed over as a
 * short-lived signed link to Pingly's own copy (the same signed media route
 * the dashboard player uses) — nothing is re-uploaded through this server.
 *
 * Facebook takes the video in one call. Instagram is two steps: create a
 * media container, wait for Instagram to finish processing it (usually
 * 30s–2min), then publish it. The waiting happens in advance(), run every
 * minute by the scheduler, so the client's request returns immediately.
 */
class MetaPublisher
{
    public const PLATFORMS = ['facebook', 'instagram'];

    public function __construct(
        private readonly MetaGraphService $graph,
        private readonly AiGatewayService $ai,
    ) {}

    /**
     * What this connection can publish to right now, and why not if not.
     *
     * @return array<string, array{available: bool, reason: ?string, target: ?string}>
     */
    public function targets(?MetaConnection $conn): array
    {
        $granted = $conn?->granted_permissions ?? [];
        $reason = fn (?string $asset, array $perms, string $what) => match (true) {
            ! $conn => 'Connect your Meta account first (Meta Ads page).',
            ! $asset => "No {$what} is linked to your Meta connection — reconnect and pick one.",
            (bool) array_diff($perms, $granted) => 'Your Meta token is missing: '.implode(', ', array_diff($perms, $granted)).'.',
            default => null,
        };

        $fb = $reason($conn?->page_id, ['pages_manage_posts', 'pages_show_list'], 'Facebook Page');
        $ig = $reason($conn?->instagram_account_id, ['instagram_content_publish', 'instagram_basic'], 'Instagram account');

        return [
            'facebook' => ['available' => $fb === null, 'reason' => $fb, 'target' => $conn?->page_name],
            'instagram' => ['available' => $ig === null, 'reason' => $ig, 'target' => $conn?->instagram_username ? '@'.$conn->instagram_username : null],
        ];
    }

    /**
     * A caption written for the platform, from the film's own storyboard.
     * Billed to the client as an ordinary AI Gateway request.
     */
    public function writeCaption(Company $company, VideoProject $project, string $language, ?string $notes): string
    {
        $board = $project->storyboard ?? [];
        $languageLine = VideoDirector::LANGUAGES[$language] ?? VideoDirector::LANGUAGES['ar-EG'];

        $response = $this->ai->forward($company, (string) config('pingly.meta.advisor_model'), [
            ['role' => 'system', 'content' => 'You are a social media copywriter for brands in Egypt and the Gulf. Write one caption for a Facebook post and Instagram Reel of this video. '
                .'Open with a hook line that stops the scroll, then two or three short lines that sell the feeling and the product, then one clear call to action, then 3-6 relevant hashtags (mix of Arabic and English where natural). '
                .'No claims the brief doesn\'t support, no fake urgency. Plain text only, no markdown, no quotes around it. '
                ."Language: {$languageLine}."],
            ['role' => 'user', 'content' => 'Brief: '.$project->prompt."\nFilm title: ".($board['title'] ?? '')."\nLogline: ".($board['logline'] ?? '')
                .($notes ? "\nWhat the client wants in the caption: {$notes}" : '')],
        ], ['max_tokens' => 600]);

        return trim((string) ($response['choices'][0]['message']['content'] ?? ''), " \n\"'");
    }

    /**
     * @param  array<int, string>  $platforms
     * @return array<int, SocialPost>
     */
    public function publish(VideoProject $project, array $platforms, string $caption, ?VideoFaceSwap $version = null): array
    {
        if ($project->status !== 'completed' || ! $project->output_path) {
            throw new RuntimeException('Only a finished video can be published.');
        }
        if ($version && ($version->video_project_id !== $project->id || $version->status !== 'completed')) {
            throw new RuntimeException('That face-swapped version is not ready yet.');
        }

        // Long enough for Meta to fetch it even when its queue is slow.
        return $this->publishMedia($project->company, [
            'kind' => 'video',
            'url' => $version
                ? URL::temporarySignedRoute('faceswap.media', now()->addHours(12), ['swap' => $version->id, 'kind' => 'result'])
                : URL::temporarySignedRoute('video.media', now()->addHours(12), ['project' => $project->id, 'kind' => 'film']),
            'title' => $project->title,
            'owner' => ['video_project_id' => $project->id],
        ], $platforms, $caption);
    }

    /**
     * @param  array<int, string>  $platforms
     * @return array<int, SocialPost>
     */
    public function publishImage(AiImage $image, array $platforms, string $caption): array
    {
        return $this->publishMedia($image->company, [
            'kind' => 'image',
            'url' => URL::temporarySignedRoute('image.media', now()->addHours(12), ['image' => $image->id]),
            'title' => null,
            'owner' => ['ai_image_id' => $image->id],
        ], $platforms, $caption);
    }

    /**
     * @param  array{kind: string, url: string, title: ?string, owner: array<string, int>}  $media
     * @param  array<int, string>  $platforms
     * @return array<int, SocialPost>
     */
    private function publishMedia(Company $company, array $media, array $platforms, string $caption): array
    {
        $conn = $company->metaConnection;
        $targets = $this->targets($conn);

        foreach ($platforms as $platform) {
            if (! ($targets[$platform]['available'] ?? false)) {
                throw new RuntimeException($targets[$platform]['reason'] ?? 'Not available.');
            }
        }

        $posts = [];
        foreach ($platforms as $platform) {
            $post = SocialPost::create([
                'company_id' => $company->id,
                ...$media['owner'],
                'platform' => $platform,
                'caption' => $caption,
                'status' => 'processing',
            ]);

            try {
                if ($platform === 'facebook') {
                    $media['kind'] === 'video'
                        ? $this->publishToFacebook($conn, $post, $media['url'], $media['title'])
                        : $this->publishPhotoToFacebook($conn, $post, $media['url']);
                } else {
                    $this->startInstagram($conn, $post, $media['url'], $media['kind']);
                }
            } catch (RuntimeException $e) {
                $post->update(['status' => 'failed', 'error' => $e->getMessage()]);
            }

            $posts[] = $post->fresh();
        }

        return $posts;
    }

    /** Finish Instagram posts whose video has been processing. Scheduler, every minute. */
    public function advance(): int
    {
        $pending = SocialPost::where('status', 'processing')->where('platform', 'instagram')
            ->whereNotNull('container_id')->with('company.metaConnection')->get();

        foreach ($pending as $post) {
            try {
                $this->finishInstagram($post);
            } catch (Throwable $e) {
                Log::warning('Instagram publish step failed', ['post' => $post->id, 'error' => $e->getMessage()]);
                $post->update(['status' => 'failed', 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Instagram publishing failed.']);
            }
        }

        return $pending->count();
    }

    /**
     * Page posts go out with the Page's own token, which the System User
     * token can ask for because the Page is assigned to it.
     */
    public function pageToken(MetaConnection $conn): string
    {
        $pageToken = $this->graph->call('GET', $conn->access_token, $conn->page_id, ['fields' => 'access_token'])['access_token'] ?? null;
        if (! $pageToken) {
            throw new RuntimeException('Meta didn\'t give access to your Page — make sure it\'s assigned to your System User with Full control.');
        }

        return $pageToken;
    }

    private function publishPhotoToFacebook(MetaConnection $conn, SocialPost $post, string $imageUrl): void
    {
        $pageToken = $this->pageToken($conn);
        $photo = $this->graph->call('POST', $pageToken, "{$conn->page_id}/photos", array_filter([
            'url' => $imageUrl,
            'caption' => $post->caption,
            'published' => 'true',
        ]));

        $permalink = null;
        try {
            $permalink = $this->graph->call('GET', $pageToken, $photo['post_id'] ?? $photo['id'], ['fields' => 'permalink_url'])['permalink_url'] ?? null;
        } catch (RuntimeException) {
            // live without a link is still live
        }

        $post->update(['status' => 'published', 'external_id' => $photo['post_id'] ?? $photo['id'], 'permalink' => $permalink, 'published_at' => now()]);
    }

    private function publishToFacebook(MetaConnection $conn, SocialPost $post, string $videoUrl, ?string $title): void
    {
        $pageToken = $this->pageToken($conn);

        $video = $this->graph->call('POST', $pageToken, "{$conn->page_id}/videos", array_filter([
            'file_url' => $videoUrl,
            'description' => $post->caption,
            'title' => $title,
            'published' => 'true',
        ]));

        $permalink = null;
        try {
            $permalink = $this->graph->call('GET', $pageToken, $video['id'], ['fields' => 'permalink_url'])['permalink_url'] ?? null;
            $permalink = $permalink && str_starts_with($permalink, '/') ? 'https://www.facebook.com'.$permalink : $permalink;
        } catch (RuntimeException) {
            // the post is live; a missing link isn't a failure
        }

        $post->update(['status' => 'published', 'external_id' => $video['id'], 'permalink' => $permalink, 'published_at' => now()]);
    }

    private function startInstagram(MetaConnection $conn, SocialPost $post, string $url, string $kind = 'video'): void
    {
        $container = $this->graph->call('POST', $conn->access_token, "{$conn->instagram_account_id}/media", $kind === 'video'
            ? ['media_type' => 'REELS', 'video_url' => $url, 'caption' => $post->caption, 'share_to_feed' => 'true']
            : ['image_url' => $url, 'caption' => $post->caption]);

        $post->update(['container_id' => $container['id']]);

        // A photo is usually ready at once — don't make the client wait a
        // scheduler tick for it.
        if ($kind === 'image') {
            try {
                $this->finishInstagram($post->fresh('company.metaConnection'));
            } catch (RuntimeException) {
                // the scheduler retries
            }
        }
    }

    private function finishInstagram(SocialPost $post): void
    {
        $conn = $post->company->metaConnection;
        if (! $conn) {
            throw new RuntimeException('Your Meta connection was removed before Instagram finished processing.');
        }

        $post->increment('attempts');
        $state = $this->graph->call('GET', $conn->access_token, $post->container_id, ['fields' => 'status_code,status'])['status_code'] ?? null;

        if (in_array($state, ['ERROR', 'EXPIRED'], true)) {
            throw new RuntimeException($post->ai_image_id ? 'Instagram couldn\'t process this image — try a 1:1 or 4:5 image.' : 'Instagram couldn\'t process this video — Reels need 3–90 seconds, ideally 9:16.');
        }
        if ($state !== 'FINISHED') {
            if ($post->attempts > 30) {
                throw new RuntimeException('Instagram took too long to process the video — try publishing again.');
            }

            return; // still processing; next tick
        }

        $media = $this->graph->call('POST', $conn->access_token, "{$conn->instagram_account_id}/media_publish", ['creation_id' => $post->container_id]);

        $permalink = null;
        try {
            $permalink = $this->graph->call('GET', $conn->access_token, $media['id'], ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (RuntimeException) {
            // live without a link is still live
        }

        $post->update(['status' => 'published', 'external_id' => $media['id'], 'permalink' => $permalink, 'published_at' => now()]);
    }
}
