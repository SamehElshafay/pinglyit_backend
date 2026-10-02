<?php

namespace Tests\Feature\Ai;

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Images reach a vision model as OpenAI/OpenRouter's multimodal parts
 * array rather than a plain string, which the endpoint originally refused
 * outright. These cover both halves of allowing it: the shape is accepted
 * and forwarded intact, and a flat per-image price is actually billed
 * rather than quietly absorbed.
 */
class VisionRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';

    /** @return array{0: Company, 1: string} */
    private function company(): array
    {
        PlatformSetting::set('openrouter_api_key', 'test-key');

        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 50]);
        [, $plaintext] = ApiKey::generate($company, 'Production');

        return [$company, $plaintext];
    }

    /** Priced per token AND per image — the case that used to leak money. */
    private function fakeUpstream(float $imagePrice = 0.002): void
    {
        Cache::flush();

        Http::fake([
            '*/models' => Http::response(['data' => [[
                'id' => 'openai/gpt-4o',
                'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002', 'image' => (string) $imagePrice],
            ]]], 200),
            '*/chat/completions' => Http::response([
                'id' => 'gen-1',
                'model' => 'openai/gpt-4o',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'A cat.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500],
            ], 200),
        ]);
    }

    public function test_a_message_carrying_an_image_is_accepted_and_forwarded_intact(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What is in this image?'],
                    ['type' => 'image_url', 'image_url' => ['url' => self::PNG]],
                ],
            ]],
        ])->assertOk()->assertJsonPath('choices.0.message.content', 'A cat.');

        // Forwarded untouched — the gateway checks the shape but must not
        // rewrite it, or a vision model stops seeing the image at all.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/chat/completions')) {
                return false;
            }

            return $request['messages'][0]['content'][1]['image_url']['url'] === self::PNG;
        });
    }

    public function test_plain_string_content_still_works(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertOk();
    }

    /**
     * The whole reason the billing change exists: tokens alone bill 0.002,
     * leaving Pingly to absorb the per-image fee on every vision request,
     * with nothing anywhere reporting a problem.
     */
    public function test_a_per_image_price_is_billed_not_absorbed(): void
    {
        [$company, $token] = $this->company();
        $this->fakeUpstream(imagePrice: 0.002);

        $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => self::PNG]],
                    ['type' => 'image_url', 'image_url' => ['url' => self::PNG]],
                ],
            ]],
        ])->assertOk();

        // 1000 * 0.000001 + 500 * 0.000002 = 0.002 in tokens,
        // plus 2 images * 0.002 = 0.004, so 0.006 in total.
        $event = UsageEvent::where('company_id', $company->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.006, (float) $event->raw_cost_to_pingly, 0.0000001);
    }

    public function test_a_model_with_no_image_price_is_unaffected(): void
    {
        [$company, $token] = $this->company();
        $this->fakeUpstream(imagePrice: 0);

        $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'image_url', 'image_url' => ['url' => self::PNG]]],
            ]],
        ])->assertOk();

        $event = UsageEvent::where('company_id', $company->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.002, (float) $event->raw_cost_to_pingly, 0.0000001);
    }

    public function test_a_malformed_part_is_rejected_before_anything_is_sent(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [['role' => 'user', 'content' => [['type' => 'video_url', 'url' => 'x']]]],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_plain_http_image_url_is_rejected(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'http://example.com/cat.png']],
            ]]],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        // Roughly 6MB once decoded, over the 5MB ceiling.
        $huge = 'data:image/png;base64,'.str_repeat('A', 8 * 1024 * 1024);

        $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => $huge]],
            ]]],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }
}
