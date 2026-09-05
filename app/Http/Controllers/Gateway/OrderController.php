<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Read-only — orders only ever come from a real AI Commerce Assistant
 * conversation (AiCommerceAgentService), never created via this API.
 */
class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->attributes->get('company')->orders()->with('items')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->get());
    }

    public function show(Request $request, Order $order)
    {
        abort_unless($order->company_id === $request->attributes->get('company')->id, 403);

        return response()->json($order->load('items'));
    }
}
