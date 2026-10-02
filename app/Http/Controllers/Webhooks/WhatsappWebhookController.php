<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\AiCommerceAgentService;
use App\Services\WhatsApp\WhatsappAutoReplyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public routes (no JWT — Meta isn't logged in). Meta requires this exact
 * verify/receive pair to exist before it'll let you save a webhook URL in
 * the app dashboard, independent of whether billing ever uses it.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsappAutoReplyService $autoReply,
        private readonly AiCommerceAgentService $commerceAgent,
    ) {}

    /**
     * The one-time handshake Meta does when you save the webhook URL in
     * the App Dashboard. Must echo back hub_challenge as plain text.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = config('pingly.whatsapp.webhook_verify_token');

        if ($mode === 'subscribe' && filled($expected) && filled($token) && hash_equals($expected, $token)) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * Inbound messages + delivery status updates land here. Billing
     * already happens at send() time (see WhatsAppGatewayService) — this
     * logs inbound messages (as $0 usage events, for the message log
     * screen) and status updates, rather than double-billing anything.
     */
    public function receive(Request $request)
    {
        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                $account = $phoneNumberId ? WhatsappAccount::where('phone_number_id', $phoneNumberId)->first() : null;

                foreach ($value['messages'] ?? [] as $message) {
                    if ($account) {
                        UsageEvent::create([
                            'company_id' => $account->company_id,
                            'service_type' => ServiceType::WhatsApp,
                            'raw_cost_to_pingly' => 0,
                            'billed_amount_to_client' => 0,
                            'metadata' => ['category' => 'inbound', 'country' => null, 'from' => $message['from'] ?? null],
                        ]);

                        $text = $message['text']['body'] ?? null;

                        // Logged before the AI runs, so an inbound message is
                        // on the record even if replying to it fails.
                        // Non-text types are recorded by their type with no
                        // body rather than dropped — the thread should show
                        // that a customer sent *something*, not a silent gap.
                        WhatsappMessage::record([
                            'company_id' => $account->company_id,
                            'customer_phone' => $message['from'] ?? 'unknown',
                            'direction' => WhatsappMessage::DIRECTION_IN,
                            'type' => $message['type'] ?? 'text',
                            'body' => $text,
                            'wa_message_id' => $message['id'] ?? null,
                            'status' => 'received',
                        ]);

                        // Both AI modes only ever fire for plain text messages
                        // today. Commerce and plain auto-reply are mutually
                        // exclusive per number — see the ai_commerce_enabled
                        // migration's docblock for why commerce wins if both
                        // are somehow on.
                        if (($message['type'] ?? null) === 'text' && filled($text) && filled($message['from'] ?? null)) {
                            if ($account->ai_commerce_enabled) {
                                $this->commerceAgent->handleInboundMessage($account, $message['from'], $text);
                            } else {
                                $this->autoReply->handleInboundMessage($account, $message['from'], $text);
                            }
                        }
                    } else {
                        Log::warning('WhatsApp inbound message for unknown phone_number_id', ['phone_number_id' => $phoneNumberId]);
                    }
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    // Meta reports sent -> delivered -> read as separate
                    // callbacks. Carrying them onto the stored row is what
                    // lets the inbox show a message actually arrived, rather
                    // than only that we handed it over.
                    $id = $status['id'] ?? null;
                    $state = $status['status'] ?? null;

                    if ($id && $state) {
                        WhatsappMessage::where('wa_message_id', $id)->update([
                            'status' => $state,
                            'error' => $status['errors'][0]['title'] ?? null,
                        ]);
                    }
                }
            }
        }

        // Meta only cares that this returns 200 quickly — it retries otherwise.
        return response('ok', 200);
    }
}
