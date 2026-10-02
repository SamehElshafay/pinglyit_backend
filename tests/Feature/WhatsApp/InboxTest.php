<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ServiceType;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\Auth\JwtService;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The message log, and the 24-hour rule that governs whether a reply is
 * even allowed. That rule is WhatsApp's, not ours, and it's the thing most
 * likely to confuse someone new to the platform — so it's enforced before
 * a send is attempted rather than discovered through a rejection.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: string, 2: string} company, jwt, api key */
    private function client(): array
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 50]);
        $user = User::factory()->create(['company_id' => $company->id]);
        [, $apiKey] = ApiKey::generate($company, 'Production');

        WhatsappAccount::create([
            'company_id' => $company->id,
            'waba_id' => '1',
            'phone_number_id' => '2',
            'access_token' => 'EAAclient-token-long-enough-here',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        app(ServiceConfigRepository::class)->setPlatformDefault(ServiceType::WhatsApp, [
            'margin_percent' => 25,
            'monthly_fee' => 0,
            'base_costs' => [['category' => 'service', 'country' => 'EG', 'cost' => 0.0]],
        ]);

        return [$company, app(JwtService::class)->issue($user, 'user')['token'], $apiKey];
    }

    private function inbound(Company $company, string $phone, string $body, $at = null): WhatsappMessage
    {
        $m = WhatsappMessage::create([
            'company_id' => $company->id,
            'customer_phone' => $phone,
            'direction' => WhatsappMessage::DIRECTION_IN,
            'type' => 'text',
            'body' => $body,
            'status' => 'received',
        ]);

        if ($at) {
            $m->forceFill(['created_at' => $at])->save();
        }

        return $m->fresh();
    }

    public function test_conversations_list_one_row_per_customer_newest_first(): void
    {
        [$company, $jwt] = $this->client();

        $this->inbound($company, '201111', 'first from A');
        $this->inbound($company, '202222', 'first from B');
        $this->inbound($company, '201111', 'second from A');

        $response = $this->withToken($jwt)->getJson('/api/whatsapp/conversations')->assertOk();

        $this->assertCount(2, $response->json());
        $this->assertSame('201111', $response->json('0.phone'));
        $this->assertSame('second from A', $response->json('0.last_message'));
    }

    public function test_a_thread_returns_messages_oldest_first_with_the_reply_window(): void
    {
        [$company, $jwt] = $this->client();
        $this->inbound($company, '201111', 'hello');

        $response = $this->withToken($jwt)
            ->getJson('/api/whatsapp/messages?phone=201111')
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'hello')
            ->assertJsonPath('reply_window.open', true);

        $this->assertNotNull($response->json('reply_window.expires_at'));
    }

    public function test_the_reply_window_closes_24_hours_after_the_last_inbound(): void
    {
        [$company, $jwt] = $this->client();
        $this->inbound($company, '201111', 'old message', now()->subHours(25));

        $this->withToken($jwt)
            ->getJson('/api/whatsapp/messages?phone=201111')
            ->assertOk()
            ->assertJsonPath('reply_window.open', false);
    }

    /** The guard that matters: refused here, so Meta never gets the chance. */
    public function test_sending_outside_the_window_is_refused_before_reaching_meta(): void
    {
        [$company, $jwt] = $this->client();
        $this->inbound($company, '201111', 'old message', now()->subHours(25));
        Http::fake();

        $this->withToken($jwt)
            ->postJson('/api/whatsapp/messages', ['phone' => '201111', 'body' => 'too late'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '24 hours'));

        Http::assertNothingSent();
    }

    public function test_a_customer_who_never_wrote_cannot_be_messaged_freely(): void
    {
        [, $jwt] = $this->client();
        Http::fake();

        $this->withToken($jwt)
            ->postJson('/api/whatsapp/messages', ['phone' => '209999', 'body' => 'cold outreach'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_reply_inside_the_window_sends_and_is_logged_as_from_the_dashboard(): void
    {
        [$company, $jwt] = $this->client();
        $this->inbound($company, '201111', 'hi there');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out1']]], 200),
        ]);

        $this->withToken($jwt)
            ->postJson('/api/whatsapp/messages', ['phone' => '201111', 'body' => 'thanks for writing'])
            ->assertOk();

        $sent = WhatsappMessage::where('direction', WhatsappMessage::DIRECTION_OUT)->firstOrFail();
        $this->assertSame('thanks for writing', $sent->body);
        $this->assertSame('dashboard', $sent->sent_by);
        $this->assertSame('wamid.out1', $sent->wa_message_id);
    }

    public function test_the_public_api_serves_the_same_log_with_a_cursor(): void
    {
        [$company, , $apiKey] = $this->client();
        $first = $this->inbound($company, '201111', 'one');
        $this->inbound($company, '201111', 'two');

        $response = $this->withToken($apiKey)
            ->getJson('/api/v1/whatsapp/messages?phone=201111&since='.$first->id)
            ->assertOk();

        // Only what came after the cursor, and a cursor to continue from.
        $this->assertCount(1, $response->json('messages'));
        $this->assertSame('two', $response->json('messages.0.body'));
        $this->assertSame($response->json('messages.0.id'), $response->json('last_id'));
    }

    public function test_one_companys_messages_are_never_visible_to_another(): void
    {
        [$a] = $this->client();
        [, $jwtB] = $this->client();

        $this->inbound($a, '201111', 'private to A');

        $this->withToken($jwtB)->getJson('/api/whatsapp/conversations')
            ->assertOk()
            ->assertJsonCount(0);

        $this->withToken($jwtB)->getJson('/api/whatsapp/messages?phone=201111')
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }
}
