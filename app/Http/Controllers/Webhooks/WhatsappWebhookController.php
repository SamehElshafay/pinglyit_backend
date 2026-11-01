<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLead;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\AiCommerceAgentService;
use App\Services\WhatsApp\WhatsappAutoReplyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

                if ($account && ! $this->signatureIsValid($request, $account)) {
                    Log::warning('WhatsApp webhook with a bad signature — ignored', ['phone_number_id' => $phoneNumberId]);

                    return response('Invalid signature', 403);
                }

                foreach ($value['messages'] ?? [] as $message) {
                    // Meta retries a delivery it isn't sure landed. The same
                    // message twice must not mean two AI replies (and two bills).
                    if (filled($message['id'] ?? null) && WhatsappMessage::where('wa_message_id', $message['id'])->exists()) {
                        continue;
                    }

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
                        if (filled($message['from'] ?? null)) {
                            $lead = WhatsappLead::for($account->company_id, $message['from']);
                            $lead->increment('messages_count', 1, [
                                'last_message_at' => now(),
                                'customer_name' => $lead->customer_name ?: ($value['contacts'][0]['profile']['name'] ?? null),
                            ]);
                        }

                        // The AI runs after Meta has its 200: a model can take
                        // longer than Meta waits, and a timed-out webhook gets
                        // redelivered — which used to mean duplicate replies.
                        if (($message['type'] ?? null) === 'text' && filled($text) && filled($message['from'] ?? null)) {
                            $from = $message['from'];
                            $messageId = $message['id'] ?? null;
                            dispatch(function () use ($account, $from, $text, $messageId) {
                                // At most one AI reply per inbound message id — also covers
                                // two copies of a delivery racing past the check above.
                                if ($messageId && ! Cache::add("wa-ai-reply:{$messageId}", 1, now()->addDay())) {
                                    return;
                                }

                                if ($account->ai_commerce_enabled) {
                                    app(AiCommerceAgentService::class)->handleInboundMessage($account, $from, $text, $messageId);
                                } else {
                                    app(WhatsappAutoReplyService::class)->handleInboundMessage($account, $from, $text);
                                }
                            })->afterResponse();
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

    /**
     * Meta signs every webhook with the sending app's secret
     * (X-Hub-Signature-256: sha256=HMAC(body)). A number connected with
     * the client's own Meta app is signed with *their* app secret, one
     * connected through Pingly's Embedded Signup with Pingly's — so either
     * may be the right key. With neither known there's nothing to check
     * against; that's accepted (and the client is asked for their secret
     * on the connect screen) rather than breaking numbers connected before
     * secrets were collected.
     */
    private function signatureIsValid(Request $request, WhatsappAccount $account): bool
    {
        $secrets = array_filter([$account->app_secret, config('pingly.whatsapp.app_secret')]);
        if (! $secrets) {
            return true;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $request->getContent(), $secret), substr($header, 7))) {
                return true;
            }
        }

        return false;
    }
}
