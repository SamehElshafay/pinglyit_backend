<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\WhatsappAccount;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The other "AI + WhatsApp" feature (AiCommerceAgentService) — a separate
 * mode from plain auto-reply (see AutoReplyTest), exercised the same way:
 * through the real inbound webhook endpoint, the shape Meta actually sends.
 */
class AiCommerceAgentTest extends TestCase
{
    use RefreshDatabase;

    private function seedWhatsappServiceCost(): void
    {
        app(ServiceConfigRepository::class)->setPlatformDefault(ServiceType::WhatsApp, [
            'margin_percent' => 25, 'monthly_fee' => 0,
            'base_costs' => [['category' => 'service', 'country' => 'EG', 'cost' => 0.0000]],
        ]);
    }

    private function makeAccount(array $overrides = []): WhatsappAccount
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 100]);

        return $company->whatsappAccounts()->create(array_merge([
            'phone_number_id' => '1234567890', 'phone_number' => '+20 100 000 0000',
            'status' => 'connected', 'connected_at' => now(),
            'ai_commerce_enabled' => true, 'ai_autoreply_model' => 'openai/gpt-4o-mini',
        ], $overrides));
    }

    private function inboundPayload(WhatsappAccount $account, string $text, string $from = '201555555555'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $account->phone_number_id],
                        'messages' => [['from' => $from, 'id' => 'wamid.test', 'type' => 'text', 'text' => ['body' => $text]]],
                    ],
                ]],
            ]],
        ];
    }

    private function chatResponse(array $message): array
    {
        return [
            'id' => 'chatcmpl-test', 'model' => 'openai/gpt-4o-mini',
            'choices' => [['message' => $message]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
        ];
    }

    public function test_it_does_nothing_when_commerce_is_disabled(): void
    {
        $this->seedWhatsappServiceCost();
        $account = $this->makeAccount(['ai_commerce_enabled' => false]);

        Http::fake();

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'Hi'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_it_answers_a_question_using_the_catalog_with_no_tool_call(): void
    {
        $this->seedWhatsappServiceCost();
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test');
        config(['pingly.whatsapp.access_token' => 'test-meta-token']);

        $account = $this->makeAccount();
        $account->company->products()->create(['name' => 'T-Shirt', 'price' => 20, 'active' => true]);

        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'role' => 'assistant', 'content' => 'We have a T-Shirt for $20!',
            ])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply']]]),
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'What do you sell?'))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/chat/completions')
                && str_contains($request['messages'][0]['content'], 'T-Shirt')
                && str_contains($request['messages'][0]['content'], '20');
        });
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com') && $r['text']['body'] === 'We have a T-Shirt for $20!');
    }

    public function test_it_creates_a_pending_order_via_a_tool_call_then_replies(): void
    {
        $this->seedWhatsappServiceCost();
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test');
        config(['pingly.whatsapp.access_token' => 'test-meta-token']);

        $account = $this->makeAccount();
        $product = $account->company->products()->create(['name' => 'T-Shirt', 'price' => 20, 'active' => true]);

        $toolCall = [
            'id' => 'call_1',
            'function' => ['name' => 'create_order', 'arguments' => json_encode(['items' => [['product_id' => $product->id, 'quantity' => 2]]])],
        ];

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push($this->chatResponse(['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]]))
                ->push($this->chatResponse(['role' => 'assistant', 'content' => 'That is 2 T-Shirts for $40 — shall I confirm?'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply']]]),
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'I want 2 t-shirts'))->assertOk();

        $order = Order::where('company_id', $account->company_id)->first();
        $this->assertNotNull($order);
        $this->assertSame('pending_confirmation', $order->status);
        $this->assertSame('201555555555', $order->customer_phone);
        $this->assertSame('40.0000', $order->total);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(2, $order->items()->first()->quantity);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com') && str_contains($r['text']['body'], 'confirm'));
    }

    public function test_it_confirms_a_pending_order_via_a_tool_call(): void
    {
        $this->seedWhatsappServiceCost();
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test');
        config(['pingly.whatsapp.access_token' => 'test-meta-token']);

        $account = $this->makeAccount();
        $order = $account->company->orders()->create([
            'customer_phone' => '201555555555', 'status' => 'pending_confirmation', 'total' => 40, 'currency' => 'USD',
        ]);

        $toolCall = ['id' => 'call_2', 'function' => ['name' => 'confirm_order', 'arguments' => json_encode(['order_id' => $order->id])]];

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push($this->chatResponse(['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]]))
                ->push($this->chatResponse(['role' => 'assistant', 'content' => "Great, order #{$order->id} is confirmed!"])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply']]]),
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'Yes, confirm it'))->assertOk();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->confirmed_at);
    }

    public function test_a_customer_cannot_confirm_another_customers_order(): void
    {
        $this->seedWhatsappServiceCost();
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test');
        config(['pingly.whatsapp.access_token' => 'test-meta-token']);

        $account = $this->makeAccount();
        $order = $account->company->orders()->create([
            'customer_phone' => '201999999999', // a different customer
            'status' => 'pending_confirmation', 'total' => 40, 'currency' => 'USD',
        ]);

        $toolCall = ['id' => 'call_3', 'function' => ['name' => 'confirm_order', 'arguments' => json_encode(['order_id' => $order->id])]];

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push($this->chatResponse(['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]]))
                ->push($this->chatResponse(['role' => 'assistant', 'content' => "I couldn't find that order."])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply']]]),
        ]);

        // A different phone number (201555555555) than the order's owner tries to confirm it.
        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'confirm order '.$order->id))->assertOk();

        $this->assertSame('pending_confirmation', $order->fresh()->status);
        Http::assertSent(function ($r) use ($order) {
            if (! str_contains($r->url(), '/chat/completions')) {
                return false;
            }
            $toolMessage = collect($r['messages'])->firstWhere('role', 'tool');

            return $toolMessage && str_contains($toolMessage['content'], 'not found');
        });
    }

    public function test_conversation_history_carries_over_to_the_next_message(): void
    {
        $this->seedWhatsappServiceCost();
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test');
        config(['pingly.whatsapp.access_token' => 'test-meta-token']);

        $account = $this->makeAccount();

        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse(['role' => 'assistant', 'content' => 'Hi there!'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply']]]),
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'Hello'))->assertOk();

        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse(['role' => 'assistant', 'content' => 'Sure, still open!'])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply2']]]),
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'Are you open?'))->assertOk();

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/chat/completions')
                && collect($r['messages'])->contains(fn ($m) => ($m['role'] ?? null) === 'user' && $m['content'] === 'Hello')
                && collect($r['messages'])->contains(fn ($m) => ($m['role'] ?? null) === 'assistant' && $m['content'] === 'Hi there!');
        });
    }

    public function test_it_ignores_non_text_messages(): void
    {
        $this->seedWhatsappServiceCost();
        $account = $this->makeAccount();

        Http::fake();

        $this->postJson('/api/webhooks/whatsapp', [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $account->phone_number_id],
                        'messages' => [['from' => '201555555555', 'id' => 'wamid.x', 'type' => 'image']],
                    ],
                ]],
            ]],
        ])->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_failed_ai_call_never_breaks_the_webhook_response(): void
    {
        $this->seedWhatsappServiceCost();
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test');

        $account = $this->makeAccount();

        Http::fake(['*/chat/completions' => Http::response(['message' => 'server error'], 500)]);

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload($account, 'Hello?'))->assertOk();
    }

    public function test_client_can_turn_on_commerce_only_after_picking_a_model(): void
    {
        $account = $this->makeAccount(['ai_commerce_enabled' => false, 'ai_autoreply_model' => null]);
        $token = app(\App\Services\Auth\JwtService::class)->issue($account->company->users()->create([
            'name' => 'Demo', 'email' => 'demo@example.test', 'password' => 'password',
        ]), 'user')['token'];

        $this->withToken($token)->putJson('/api/services/whatsapp/commerce', ['enabled' => true])->assertStatus(422);

        $this->withToken($token)->putJson('/api/services/whatsapp/commerce', [
            'enabled' => true, 'model' => 'openai/gpt-4o-mini',
        ])->assertOk()->assertJson(['enabled' => true, 'model' => 'openai/gpt-4o-mini']);

        $this->assertTrue($account->fresh()->ai_commerce_enabled);
    }
}
