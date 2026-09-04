<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappAccount;
use App\Services\Ai\AiGatewayService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The actual "connect AI to WhatsApp" feature: when a company turns this on
 * for a connected number, every inbound text message gets a reply generated
 * by the AI Gateway and sent straight back — the client's own customers talk
 * to their AI, not to a human, without the client writing any of this
 * plumbing themselves.
 *
 * Deliberately simple for a first version: stateless, single-turn (each
 * inbound message gets its own reply with no memory of earlier ones in the
 * conversation), text messages only (images/audio/documents are ignored).
 * Multi-turn memory needs a real conversation-history table and is a
 * reasonable next step, not something to fake now.
 *
 * Called from WhatsappWebhookController::receive() — never lets an
 * exception escape, since a broken auto-reply must never turn Meta's
 * webhook delivery into a failure (Meta retries a non-200 aggressively).
 */
class WhatsappAutoReplyService
{
    public function __construct(
        private readonly AiGatewayService $ai,
        private readonly WhatsAppGatewayService $whatsapp,
    ) {}

    public function handleInboundMessage(WhatsappAccount $account, string $from, string $text): void
    {
        if (! $account->ai_autoreply_enabled || blank($account->ai_autoreply_model)) {
            return;
        }

        $company = $account->company;

        try {
            $messages = [];
            if (filled($account->ai_autoreply_system_prompt)) {
                $messages[] = ['role' => 'system', 'content' => $account->ai_autoreply_system_prompt];
            }
            $messages[] = ['role' => 'user', 'content' => $text];

            $result = $this->ai->forward($company, $account->ai_autoreply_model, $messages);
            $reply = $result['choices'][0]['message']['content'] ?? null;

            if (blank($reply)) {
                Log::warning('WhatsApp AI auto-reply: model returned no content', [
                    'company_id' => $company->id,
                    'model' => $account->ai_autoreply_model,
                ]);

                return;
            }

            // 'service' — a reply within the 24h window the customer opened by
            // messaging first — is the one category that's actually free from
            // Meta, and the only one that fits a reactive auto-reply. Country
            // is a known simplification: real Meta pricing keys off the
            // recipient's own number, not hardcoded here — but 'service' is
            // priced at $0 in the seeded cost table regardless of country, so
            // this rarely matters in practice until a real per-number country
            // lookup gets built. See WhatsappGatewayService::estimateCost().
            $this->whatsapp->send($company, $from, 'service', 'EG', [
                'type' => 'text',
                'text' => ['body' => $reply],
            ]);
        } catch (Throwable $e) {
            Log::warning('WhatsApp AI auto-reply failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
