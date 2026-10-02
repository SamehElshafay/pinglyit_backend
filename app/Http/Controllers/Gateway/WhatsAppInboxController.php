<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Client\WhatsappInboxController as DashboardInbox;
use App\Http\Controllers\Controller;
use App\Models\WhatsappMessage;
use Illuminate\Http\Request;

/**
 * The message log, served to a client's own application.
 *
 * Deliberately reuses the dashboard controller's presentation and
 * reply-window logic rather than restating it: an inbox someone builds on
 * this API should behave identically to the one Pingly shows, including the
 * 24-hour rule, and two copies of that logic would drift.
 */
class WhatsAppInboxController extends Controller
{
    /** GET /v1/whatsapp/conversations */
    public function conversations(Request $request)
    {
        $company = $request->attributes->get('company');

        return response()->json(
            DashboardInbox::conversationsFor($company, (int) $request->integer('limit', 50))
        );
    }

    /**
     * GET /v1/whatsapp/messages?phone=…&since=…
     *
     * `since` takes a message id rather than a timestamp, so polling for new
     * messages can't miss one that landed inside the same second as the last
     * poll — the usual way a naive timestamp cursor loses messages.
     */
    public function messages(Request $request)
    {
        $data = $request->validate([
            'phone' => ['sometimes', 'string', 'max:32'],
            'since' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $company = $request->attributes->get('company');

        $query = WhatsappMessage::forCompany($company->id)->orderBy('id');

        if (isset($data['phone'])) {
            $query->withCustomer($data['phone']);
        }

        if (isset($data['since'])) {
            $query->where('id', '>', $data['since']);
        }

        $messages = $query->limit($data['limit'] ?? 100)->get();

        return response()->json([
            'messages' => $messages->map(fn (WhatsappMessage $m) => DashboardInbox::present($m)),
            // The cursor to pass back as `since` next time. Returned
            // explicitly so a caller never has to work it out from the list,
            // and an empty page still advances nothing by accident.
            'last_id' => $messages->last()?->id ?? ($data['since'] ?? 0),
        ]);
    }
}
