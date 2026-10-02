<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The message log, read and written by a person in the dashboard.
 *
 * The same data is served to a client's own application by
 * Gateway\WhatsAppInboxController — one source, two audiences, so an inbox
 * built on the public API can never drift from the one Pingly shows.
 */
class WhatsappInboxController extends Controller
{
    public function __construct(private readonly WhatsAppGatewayService $whatsapp) {}

    /**
     * One row per customer: their latest message, how many are unanswered,
     * and whether a free-form reply is still allowed.
     */
    public function conversations(Request $request)
    {
        $company = $request->user()->company;

        return response()->json(
            static::conversationsFor($company, (int) $request->integer('limit', 50))
        );
    }

    public function messages(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $company = $request->user()->company;

        $messages = WhatsappMessage::forCompany($company->id)
            ->withCustomer($data['phone'])
            ->orderBy('id')
            ->limit($data['limit'] ?? 100)
            ->get()
            ->map(fn (WhatsappMessage $m) => static::present($m));

        return response()->json([
            'phone' => $data['phone'],
            'messages' => $messages,
            'reply_window' => static::replyWindowFor($company, $data['phone']),
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $company = $request->user()->company;

        // Checked before spending anything: outside the 24-hour window Meta
        // refuses a free-form message, and the client would be left with a
        // rejection they can't interpret. Better to say so plainly here.
        $window = static::replyWindowFor($company, $data['phone']);

        if (! $window['open']) {
            return response()->json([
                'message' => 'This customer last wrote more than 24 hours ago, so WhatsApp no longer allows a free-form reply. They need to message you again first.',
            ], 422);
        }

        try {
            $this->whatsapp->send(
                $company,
                $data['phone'],
                'service',
                'EG',
                ['type' => 'text', 'text' => ['body' => $data['body']]],
                'dashboard',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'messages' => WhatsappMessage::forCompany($company->id)
                ->withCustomer($data['phone'])
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->map(fn (WhatsappMessage $m) => static::present($m)),
            'reply_window' => static::replyWindowFor($company, $data['phone']),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function conversationsFor(Company $company, int $limit = 50): array
    {
        // Latest row per customer. Done with one grouped query plus one
        // fetch rather than a per-customer query, so a busy account doesn't
        // turn the inbox into hundreds of round trips.
        $latestIds = WhatsappMessage::forCompany($company->id)
            ->selectRaw('MAX(id) as id')
            ->groupBy('customer_phone')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');

        return WhatsappMessage::whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->get()
            ->map(fn (WhatsappMessage $m) => [
                'phone' => $m->customer_phone,
                'last_message' => $m->body,
                'last_direction' => $m->direction,
                'last_at' => $m->created_at,
                'reply_window' => static::replyWindowFor($company, $m->customer_phone),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether a free-form reply is still allowed, and when that stops.
     *
     * @return array{open: bool, expires_at: ?string}
     */
    public static function replyWindowFor(Company $company, string $phone): array
    {
        $lastInbound = WhatsappMessage::forCompany($company->id)
            ->withCustomer($phone)
            ->where('direction', WhatsappMessage::DIRECTION_IN)
            ->latest('id')
            ->first();

        if (! $lastInbound) {
            return ['open' => false, 'expires_at' => null];
        }

        $expires = Carbon::parse($lastInbound->created_at)->addHours(WhatsappMessage::REPLY_WINDOW_HOURS);

        return [
            'open' => $expires->isFuture(),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function present(WhatsappMessage $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'type' => $m->type,
            'body' => $m->body,
            'status' => $m->status,
            'error' => $m->error,
            'sent_by' => $m->sent_by,
            'time' => $m->created_at,
        ];
    }
}
