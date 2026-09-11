<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\WhatsappAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Self-service "Connect WhatsApp number" for a client, via Meta's Embedded
 * Signup — replaces the only alternative today (an admin manually inserting
 * a whatsapp_accounts row by hand, see that migration's docblock) with a
 * flow the client runs themselves from user_website, in a few minutes,
 * without ever leaving Pingly's own dashboard or touching Meta Business
 * Manager directly.
 *
 * Requires Pingly to be registered as a Meta **Tech Provider** (App
 * Dashboard → WhatsApp → Embedded Signup → Configurations, which is where
 * `config_id` below comes from) — a one-time setup step, separate from
 * this code. See the frontend's WhatsAppEmbeddedSignupButton.vue for the
 * other half of the flow (FB.login() + the postMessage listener that
 * produces the `code`/`wabaId`/`phoneNumberId` this class is handed).
 *
 * Deliberately does *not* persist the token this exchanges the `code` for:
 * WhatsAppGatewayService already sends every company's messages through
 * one shared system-user token (`config('pingly.whatsapp.access_token')`,
 * a Tech Provider's own long-lived token, not a per-client one) — that's
 * the architecture whatsapp_accounts' own migration docblock already
 * committed to. The exchange still has to happen (Meta's documented flow
 * requires it within 30 seconds to actually finalize the access grant to
 * the client's WABA), its result is just never stored beyond this method.
 *
 * Built from Meta's public Embedded Signup docs — not verified against a
 * real completed flow (no approved Tech Provider account existed while
 * writing this), so the first real signup is the actual test. The two
 * things most likely to need a tweak if it is: the OAuth code-exchange
 * endpoint (Meta's own implementation guide doesn't spell out its exact
 * shape, only that "a server-to-server call" exchanges the code — this
 * uses the standard `GET /oauth/access_token` Facebook Login endpoint,
 * the closest documented match), and the phone number's registration PIN
 * requirement (Meta's docs describe it for phone numbers registered
 * programmatically in general, but Embedded Signup may in fact issue an
 * already-registered number for some accounts — see registerPhoneNumber()).
 */
class WhatsAppEmbeddedSignupService
{
    public function appId(): ?string
    {
        return config('pingly.whatsapp.app_id');
    }

    public function configId(): ?string
    {
        return config('pingly.whatsapp.config_id');
    }

    public function isConfigured(): bool
    {
        return filled($this->appId())
            && filled(config('pingly.whatsapp.app_secret'))
            && filled($this->configId())
            && filled(config('pingly.whatsapp.access_token'));
    }

    /**
     * Completes the connection for one company: exchanges the code (to
     * finalize Meta's access grant), subscribes Pingly's app to the
     * client's WABA (so their inbound messages/status updates actually
     * reach WhatsappWebhookController), registers the phone number for
     * Cloud API sending, and records the result.
     *
     * @throws RuntimeException on any step Meta rejects — nothing here is
     *                          allowed to half-succeed silently; a failed connect must
     *                          leave the client able to just try again; see whichever
     *                          step's own docblock for what a retry-safe failure means.
     */
    public function completeSignup(Company $company, string $code, string $wabaId, string $phoneNumberId): WhatsappAccount
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WhatsApp Embedded Signup is not configured — set META_WHATSAPP_CONFIG_ID (and the rest of META_WHATSAPP_*) first.');
        }

        $this->exchangeCode($code);
        $this->subscribeApp($wabaId);

        $pin = (string) random_int(100000, 999999);
        $this->registerPhoneNumber($phoneNumberId, $pin);

        return WhatsappAccount::updateOrCreate(
            ['company_id' => $company->id, 'waba_id' => $wabaId],
            [
                'phone_number_id' => $phoneNumberId,
                'registration_pin' => $pin,
                'phone_number' => $this->fetchDisplayPhoneNumber($phoneNumberId),
                'status' => 'connected',
                'connected_at' => now(),
            ],
        );
    }

    /**
     * Finalizes the access grant Embedded Signup made to Pingly's app —
     * Meta's docs: the `code` returned to the frontend has a 30-second
     * time-to-live and "must" be exchanged server-side. The token this
     * returns is intentionally discarded — see the class docblock for why.
     */
    private function exchangeCode(string $code): void
    {
        $response = $this->request()->get('https://graph.facebook.com/'.$this->apiVersion().'/oauth/access_token', [
            'client_id' => $this->appId(),
            'client_secret' => config('pingly.whatsapp.app_secret'),
            'code' => $code,
        ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException("Couldn't finalize the WhatsApp connection with Meta: ".$response->body());
        }
    }

    /**
     * Without this, the client's inbound messages and status updates never
     * reach WhatsappWebhookController — Meta only calls a webhook for
     * WABAs the calling app has actually subscribed to. Idempotent: safe
     * to call again on a reconnect, Meta just reports the app already
     * subscribed.
     */
    private function subscribeApp(string $wabaId): void
    {
        $response = $this->request()
            ->post("https://graph.facebook.com/{$this->apiVersion()}/{$wabaId}/subscribed_apps");

        if ($response->failed()) {
            throw new RuntimeException("Couldn't subscribe to this WhatsApp Business Account's webhooks: ".$response->body());
        }
    }

    /**
     * Cloud API refuses to send through a number that isn't registered —
     * this is that one-time activation call, with a PIN Pingly generates
     * (never the client's choice) and stores in case Meta ever asks for it
     * again (e.g. after the number gets deregistered).
     */
    private function registerPhoneNumber(string $phoneNumberId, string $pin): void
    {
        $response = $this->request()
            ->post("https://graph.facebook.com/{$this->apiVersion()}/{$phoneNumberId}/register", [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Couldn't register the phone number with Meta: ".$response->body());
        }
    }

    /**
     * The human-readable number ("+20 100 000 0000"), for display only —
     * never used to address a Cloud API call (phone_number_id is). Best
     * effort: worth having but not worth failing the whole connection over,
     * so a failure here is logged, not thrown.
     */
    private function fetchDisplayPhoneNumber(string $phoneNumberId): ?string
    {
        try {
            $response = $this->request()
                ->get("https://graph.facebook.com/{$this->apiVersion()}/{$phoneNumberId}", ['fields' => 'display_phone_number']);

            return $response->json('display_phone_number');
        } catch (ConnectionException $e) {
            Log::warning('Could not fetch the display phone number after a WhatsApp Embedded Signup connect.', [
                'phone_number_id' => $phoneNumberId,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function request()
    {
        return Http::withToken(config('pingly.whatsapp.access_token'))->timeout(30);
    }

    private function apiVersion(): string
    {
        return config('pingly.whatsapp.api_version');
    }
}
