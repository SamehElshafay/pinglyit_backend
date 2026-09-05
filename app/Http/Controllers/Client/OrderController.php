<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Read (+ a manual status override) for orders the AI Commerce Assistant
 * placed — see AiCommerceAgentService. Orders are never created from here;
 * they only ever come from a real WhatsApp conversation.
 */
class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->company->orders()->with('items')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->get());
    }

    public function show(Request $request, Order $order)
    {
        abort_unless($order->company_id === $request->user()->company_id, 403);

        return response()->json($order->load('items'));
    }

    /**
     * A human override for the rare case the AI's own confirm/cancel tool
     * calls didn't cover — e.g. the customer confirmed over a phone call
     * instead of on WhatsApp.
     */
    public function updateStatus(Request $request, Order $order)
    {
        abort_unless($order->company_id === $request->user()->company_id, 403);

        $data = $request->validate(['status' => ['required', 'string', 'in:confirmed,cancelled']]);

        abort_unless($order->status === 'pending_confirmation', 422, 'Only a pending order can be updated this way.');

        $order->update(['status' => $data['status'], 'confirmed_at' => $data['status'] === 'confirmed' ? now() : null]);

        return response()->json($order->fresh('items'));
    }
}
