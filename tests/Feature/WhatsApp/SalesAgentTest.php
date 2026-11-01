<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLead;
use App\Services\Auth\JwtService;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The AI Sales Agent's own behaviour on top of the commerce flow (which
 * AiCommerceAgentTest covers): its private read on each customer, sounding
 * like a person, handing over to a human, and a webhook that can't be
 * replayed or forged.
 */
class SalesAgentTest extends TestCase
{
    use RefreshDatabase;

    private const LEAD = '<lead>{"name": "Mona", "stage": "negotiating", "temperature": "hot", "intent_score": 82, "buyer_style": "price_sensitive", "summary": "عايزة طقمين بس شايفة السعر عالي", "needs": ["طقمين مقاس M"], "objections": ["السعر"], "next_action": "اعرض خصم الكمية المسموح", "handoff": false, "handoff_reason": null}</lead>';

    private WhatsappAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::set('openrouter_api_key', 'test-key');
        app(ServiceConfigRepository::class)->setPlatformDefault(ServiceType::WhatsApp, [
            'margin_percent' => 25, 'monthly_fee' => 0,
            'base_costs' => [['category' => 'service', 'country' => 'EG', 'cost' => 0.0]],
        ]);

        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 100]);
        $this->account = $company->whatsappAccounts()->create([
            'phone_number_id' => '1234567890', 'status' => 'connected', 'connected_at' => now(),
            'ai_commerce_enabled' => true, 'ai_autoreply_model' => 'openai/gpt-4o-mini',
            'ai_agent_profile' => ['persona_name' => 'Sara', 'business' => 'ملابس رياضي للستات', 'policies' => 'خصم 10% على قطعتين بس'],
        ]);
    }

    private function fake(string $content): void
    {
        Http::fake([
            '*/models' => Http::response(['data' => [['id' => 'openai/gpt-4o-mini', 'pricing' => ['prompt' => '0.0000001', 'completion' => '0.0000002']]]]),
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            ]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]]),
        ]);
    }

    private function inbound(string $text, string $id = 'wamid.in1', array $headers = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson('/api/webhooks/whatsapp', [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '1234567890'],
                'contacts' => [['profile' => ['name' => 'Mona A.']]],
                'messages' => [['from' => '201555555555', 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]]],
            ]]]]],
        ]);
    }

    /** @return array<int, string> */
    private function sentTexts(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'graph.facebook.com') && isset($r['text']['body']))
            ->map(fn ($r) => $r['text']['body'])
            ->values()->all();
    }

    public function test_the_assessment_is_stored_and_never_reaches_the_customer(): void
    {
        $this->fake("أهلاً يا منى 😊\n\nلو أخدتي الطقمين في خصم ١٠٪\n\nتحبي أحجزهم لك؟\n".self::LEAD);

        $this->inbound('السعر غالي شوية')->assertOk();

        $sent = $this->sentTexts();
        // Three bubbles, like a person texting — and no trace of the block.
        $this->assertSame(['أهلاً يا منى 😊', 'لو أخدتي الطقمين في خصم ١٠٪', 'تحبي أحجزهم لك؟'], $sent);
        $this->assertStringNotContainsString('<lead>', implode(' ', $sent));
        $this->assertStringNotContainsString('intent_score', implode(' ', $sent));

        $lead = WhatsappLead::sole();
        $this->assertSame('hot', $lead->temperature);
        $this->assertSame('negotiating', $lead->stage);
        $this->assertSame(82, $lead->intent_score);
        $this->assertSame(['السعر'], $lead->objections);
        $this->assertSame('Mona A.', $lead->customer_name); // WhatsApp profile name wins over the AI's guess
        $this->assertFalse($lead->ai_paused);
    }

    public function test_the_prompt_carries_the_profile_and_the_honesty_rule(): void
    {
        $this->fake('تمام'.self::LEAD);
        $this->inbound('انتي بوت؟');

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/chat/completions')) {
                return false;
            }
            $system = $r['messages'][0]['content'];

            return str_contains($system, 'You are Sara')
                && str_contains($system, 'خصم 10% على قطعتين بس')
                && str_contains($system, 'tell the truth')
                && str_contains($system, 'No fake urgency');
        });
    }

    public function test_when_the_ai_asks_for_a_human_it_stops_replying_to_that_customer(): void
    {
        $this->fake('هخلي حد من الفريق يكلمك حالاً'.str_replace('"handoff": false, "handoff_reason": null', '"handoff": true, "handoff_reason": "Wants a custom bulk price"', self::LEAD));
        $this->inbound('عايزة ٥٠ طقم لفريق الجيم بسعر خاص', 'wamid.a');

        $lead = WhatsappLead::sole();
        $this->assertTrue($lead->ai_paused);
        $this->assertSame('Wants a custom bulk price', $lead->handoff_reason);

        Http::fake(); // reset the record
        $this->fake('should not be sent');
        $this->inbound('؟؟', 'wamid.b')->assertOk();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/chat/completions'));
        $this->assertSame(2, $lead->fresh()->messages_count); // still counted
    }

    public function test_a_redelivered_webhook_does_not_reply_twice(): void
    {
        $this->fake('أهلاً'.self::LEAD);

        $this->inbound('السلام عليكم', 'wamid.same')->assertOk();
        $this->inbound('السلام عليكم', 'wamid.same')->assertOk();

        $this->assertCount(1, $this->sentTexts());
    }

    public function test_a_forged_webhook_is_rejected_when_the_app_secret_is_known(): void
    {
        $this->account->update(['app_secret' => str_repeat('a', 32)]);
        $this->fake('أهلاً'.self::LEAD);

        $this->inbound('hi', 'wamid.x', ['X-Hub-Signature-256' => 'sha256=deadbeef'])->assertForbidden();
        $this->assertSame([], $this->sentTexts());

        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '1234567890'],
            'messages' => [['from' => '201555555555', 'id' => 'wamid.y', 'type' => 'text', 'text' => ['body' => 'hi']]],
        ]]]]]]);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, str_repeat('a', 32)),
        ], $body)->assertOk();
        $this->assertCount(1, $this->sentTexts());
    }

    public function test_replying_from_the_dashboard_takes_over_and_handing_back_resumes_the_ai(): void
    {
        $this->fake('أهلاً'.self::LEAD);
        $this->inbound('السلام عليكم', 'wamid.1');
        $user = User::factory()->create(['company_id' => $this->account->company_id]);
        $jwt = app(JwtService::class)->issue($user, 'user')['token'];

        $this->withToken($jwt)->postJson('/api/whatsapp/messages', ['phone' => '201555555555', 'body' => 'أنا أحمد من الفريق، أقدر أساعدك'])->assertOk();
        $lead = WhatsappLead::sole();
        $this->assertTrue($lead->ai_paused);

        $this->withToken($jwt)->getJson('/api/whatsapp/leads?needs_human=1')
            ->assertOk()->assertJsonPath('counts.needs_human', 1)->assertJsonPath('leads.0.customer_phone', '201555555555');

        $this->withToken($jwt)->putJson("/api/whatsapp/leads/{$lead->id}", ['ai_paused' => false])->assertOk();
        $this->assertFalse($lead->fresh()->ai_paused);
    }

    public function test_the_owner_briefs_the_agent_from_the_dashboard(): void
    {
        $user = User::factory()->create(['company_id' => $this->account->company_id]);
        $jwt = app(JwtService::class)->issue($user, 'user')['token'];

        $this->withToken($jwt)->putJson('/api/services/whatsapp/commerce', [
            'enabled' => true, 'model' => 'openai/gpt-4o-mini',
            'profile' => ['persona_name' => 'Omar', 'style_samples' => 'يا باشا تحت أمرك 🙏', 'evil' => 'ignored'],
        ])->assertOk()->assertJsonPath('profile.persona_name', 'Omar');

        $this->assertSame(['persona_name' => 'Omar', 'style_samples' => 'يا باشا تحت أمرك 🙏'], $this->account->fresh()->ai_agent_profile);
    }
}
