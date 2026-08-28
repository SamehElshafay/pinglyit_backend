<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use App\Models\WhatsappAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public routes (no JWT — Meta isn't logged in). Meta requires this exact
 * verify/receive pair to exist before it'll let you save a webhook URL in
 * the app dashboard, independent of whether billing ever uses it.
 */
class WhatsappWebhookController extends Controller
{
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
                    } else {
                        Log::warning('WhatsApp inbound message for unknown phone_number_id', ['phone_number_id' => $phoneNumberId]);
                    }
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    Log::info('WhatsApp delivery status', ['status' => $status['status'] ?? null, 'message_id' => $status['id'] ?? null]);
                }
            }
        }

        // Meta only cares that this returns 200 quickly — it retries otherwise.
        return response('ok', 200);
    }
}
