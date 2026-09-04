<?php

namespace App\Http\Controllers\Client;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiGatewayService;
use App\Services\WhatsApp\WhatsAppGatewayService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(
        private readonly WhatsAppGatewayService $whatsapp,
        private readonly AiGatewayService $ai,
    ) {}

    public function index(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            ['key' => 'whatsapp', 'name' => ServiceType::WhatsApp->label(), 'enabled' => $this->whatsapp->isEnabledFor($company)],
            ['key' => 'ai', 'name' => ServiceType::Ai->label(), 'enabled' => $this->ai->isEnabledFor($company)],
        ]);
    }

    public function whatsapp(Request $request)
    {
        $company = $request->user()->company;
        $account = $company->whatsappAccounts()->where('status', 'connected')->first();

        return response()->json([
            'connected' => (bool) $account,
            'accounts' => $company->whatsappAccounts,
            'usage' => $company->usageEvents()->where('service_type', ServiceType::WhatsApp)->latest()->limit(25)->get()
                ->map(fn ($e) => [
                    'time' => $e->created_at,
                    'category' => $e->metadata['category'] ?? null,
                    'country' => $e->metadata['country'] ?? null,
                    'cost' => (float) $e->billed_amount_to_client,
                ]),
            // The actual "connect AI to WhatsApp" feature — see
            // WhatsappAutoReplyService. autoreply_models is the same
            // priced-for-this-client catalog the AI Gateway page already uses.
            'autoreply' => $account ? [
                'enabled' => $account->ai_autoreply_enabled,
                'model' => $account->ai_autoreply_model,
                'system_prompt' => $account->ai_autoreply_system_prompt,
            ] : null,
            'autoreply_models' => $this->ai->availableModelsFor($company),
        ]);
    }

    /**
     * Turn Pingly's WhatsApp AI auto-reply on/off for this company's
     * connected number, and configure what it uses to reply — see
     * WhatsappAutoReplyService for what actually happens with these once set.
     */
    public function updateWhatsappAutoReply(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'model' => ['nullable', 'string'],
            'system_prompt' => ['nullable', 'string', 'max:4000'],
        ]);

        $company = $request->user()->company;
        $account = $company->whatsappAccounts()->where('status', 'connected')->first();

        if (! $account) {
            return response()->json(['message' => 'Connect a WhatsApp number first.'], 422);
        }

        if ($data['enabled'] && blank($data['model'] ?? null)) {
            return response()->json(['message' => 'Pick a model before turning auto-reply on.'], 422);
        }

        $account->update([
            'ai_autoreply_enabled' => $data['enabled'],
            'ai_autoreply_model' => $data['model'] ?? null,
            'ai_autoreply_system_prompt' => $data['system_prompt'] ?? null,
        ]);

        return response()->json([
            'enabled' => $account->ai_autoreply_enabled,
            'model' => $account->ai_autoreply_model,
            'system_prompt' => $account->ai_autoreply_system_prompt,
        ]);
    }

    public function ai(Request $request)
    {
        $company = $request->user()->company;

        // Deliberately no multiplier, and no raw OpenRouter token count —
        // "credits" is Pingly's own billing unit, not a claim that it equals
        // what OpenRouter itself counted (real_tokens × multiplier would
        // let anyone who knows the real count back out the multiplier).
        return response()->json([
            'balance' => (float) ($company->wallet->balance ?? 0),
            'usage' => $company->usageEvents()->where('service_type', ServiceType::Ai)->latest()->limit(25)->get()
                ->map(fn ($e) => [
                    'time' => $e->created_at,
                    'model' => $e->metadata['model'] ?? null,
                    'credits' => $e->metadata['billed_tokens'] ?? null,
                ]),
        ]);
    }

    /**
     * The models this client can actually pick — whatever the admin has
     * priced for them, not the full OpenRouter catalog. Powers the model
     * Select on the dashboard's "try a request" box.
     */
    public function aiModels(Request $request)
    {
        return response()->json($this->ai->availableModelsFor($request->user()->company));
    }

    /**
     * Send one WhatsApp message through the gateway — this is the actual
     * "use the service" endpoint, as opposed to whatsapp() above which
     * just reports on past usage.
     */
    public function sendWhatsapp(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'category' => ['required', 'string', 'in:utility,authentication,marketing,service'],
            'country' => ['required', 'string', 'size:2'],
            'text' => ['required', 'string', 'max:4096'],
        ]);

        $company = $request->user()->company;

        if (! $this->whatsapp->isEnabledFor($company)) {
            return response()->json(['message' => 'WhatsApp Gateway is not enabled for this account.'], 403);
        }

        try {
            $result = $this->whatsapp->send(
                $company,
                $data['to'],
                $data['category'],
                strtoupper($data['country']),
                ['type' => 'text', 'text' => ['body' => $data['text']]],
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * Send one AI Gateway request — same relationship to ai() above.
     */
    public function chatAi(Request $request)
    {
        $data = $request->validate([
            'model' => ['required', 'string'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string'],
            'messages.*.tool_calls' => ['sometimes', 'array'],
            'messages.*.tool_call_id' => ['sometimes', 'string'],
            'messages.*.name' => ['sometimes', 'string'],
            'tools' => ['sometimes', 'array'],
            'tool_choice' => ['sometimes'],
        ]);

        $company = $request->user()->company;

        if (! $this->ai->isEnabledFor($company)) {
            return response()->json(['message' => 'AI Gateway is not enabled for this account.'], 403);
        }

        try {
            $result = $this->ai->forward($company, $data['model'], $data['messages'], [
                'tools' => $data['tools'] ?? null,
                'tool_choice' => $data['tool_choice'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Whitelisted, not a passthrough of $result — OpenRouter's raw
        // response carries a `usage` block with the real token count, which
        // next to the credits shown on the usage screen would hand anyone
        // the multiplier for free (real_tokens vs. credits charged).
        return response()->json([
            'id' => $result['id'] ?? null,
            'model' => $result['model'] ?? $data['model'],
            'choices' => $result['choices'] ?? [],
        ]);
    }
}
