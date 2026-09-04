<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\WhatsappAccount;
use App\Services\Auth\JwtService;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The actual "connect AI to WhatsApp" feature (WhatsappAutoReplyService),
 * exercised end to end through the real inbound webhook endpoint — the
 * shape Meta actually sends, not a shortcut straight into the service.
 */
class AutoReplyTest extends TestCase
{
    use RefreshDatabase;

    private function seedWhatsappServiceCost(): void
    {
        app(ServiceConfigRepository::class)->setPlatformDefault(ServiceType::WhatsApp, [
            'margin_percent' => 25,
            'monthly_fee' => 0,
            'base_costs' => [
                ['category' => 'service', 'country' => 'EG', 'cost' => 0.0000],
            ],
        ]);
    }

    private function makeAccount(array $overrides = []): WhatsappAccount
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 100]);

        return $company->whatsappAccounts()->create(array_merge([
            'phone_number_id' => '1234567890',
            'phone_number' => '+20 100 000 0000',
            'status' => 'connected',
            'connected_at' => now(),
        ], $overrides));
    }

    private function inboundPayload(WhatsappAccount $account, string $text, string $type = 'text'): array
    {
        $message = ['from' => '201555555555', 'id' => 'wamid.test123', 'type' => $type];
        if ($type === 'text') {
            $message['text'] = ['body' => $text];
        }

        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $account->phone_number_id],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }

    public function test_it_does_nothing_when_autoreply_is_disabled(): void
    {
        $this->seedWhatsappServiceCost();
        $account = $this->makeAccount(['ai_autoreply_enabled' => false]);

        Http::fake(); // any call at all would be a bug here

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'Hi, are you open?'))
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_it_replies_via_ai_when_enabled_and_configured(): void
    {
        $this->seedWhatsappServiceCost();
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test');
        config(['pingly.whatsapp.access_token' => 'test-meta-token']);

        $account = $this->makeAccount([
            'ai_autoreply_enabled' => true,
            'ai_autoreply_model' => 'openai/gpt-4o-mini',
            'ai_autoreply_system_prompt' => 'You are a helpful assistant for Acme Inc.',
        ]);

        Http::fake([
            '*/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'model' => 'openai/gpt-4o-mini',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Yes, we are open until 9pm!']]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            ]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply123']]]),
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'Are you open?'))
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/chat/completions')
                && $request['model'] === 'openai/gpt-4o-mini'
                && $request['messages'][0] === ['role' => 'system', 'content' => 'You are a helpful assistant for Acme Inc.']
                && $request['messages'][1] === ['role' => 'user', 'content' => 'Are you open?'];
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com')
                && $request['to'] === '201555555555'
                && $request['text']['body'] === 'Yes, we are open until 9pm!';
        });
    }

    public function test_it_ignores_non_text_messages(): void
    {
        $this->seedWhatsappServiceCost();
        $account = $this->makeAccount(['ai_autoreply_enabled' => true, 'ai_autoreply_model' => 'openai/gpt-4o-mini']);

        Http::fake();

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, '', 'image'))
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_failed_ai_call_never_breaks_the_webhook_response(): void
    {
        $this->seedWhatsappServiceCost();
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test');

        $account = $this->makeAccount(['ai_autoreply_enabled' => true, 'ai_autoreply_model' => 'openai/gpt-4o-mini']);

        Http::fake(['*/chat/completions' => Http::response(['message' => 'server error'], 500)]);

        // Never a 500 to Meta — it retries aggressively on anything but 2xx.
        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'Hello?'))
            ->assertOk();
    }

    public function test_client_can_turn_on_autoreply_only_after_picking_a_model(): void
    {
        $account = $this->makeAccount();
        $token = app(JwtService::class)->issue($account->company->users()->create([
            'name' => 'Demo', 'email' => 'demo@example.test', 'password' => 'password',
        ]), 'user')['token'];

        $this->withToken($token)->putJson('/api/services/whatsapp/autoreply', ['enabled' => true])
            ->assertStatus(422);

        $response = $this->withToken($token)->putJson('/api/services/whatsapp/autoreply', [
            'enabled' => true,
            'model' => 'openai/gpt-4o-mini',
            'system_prompt' => 'Be concise.',
        ]);

        $response->assertOk()->assertJson(['enabled' => true, 'model' => 'openai/gpt-4o-mini']);
        $this->assertTrue($account->fresh()->ai_autoreply_enabled);
    }
}
