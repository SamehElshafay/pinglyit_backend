<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\WalletAdjustment;
use App\Services\Billing\BillingEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WalletAdjustmentController extends Controller
{
    public function __construct(private readonly BillingEngine $billing) {}

    /**
     * Every manual adjustment across every client (screen-map: /billing/adjustments).
     * Pass ?company_id= to scope it to one client's Wallet tab instead.
     */
    public function index(Request $request)
    {
        $adjustments = WalletAdjustment::with('company:id,name', 'admin:id,name')
            ->when($request->integer('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->latest()
            ->paginate(25);

        return response()->json($adjustments->through(fn ($a) => [
            'id' => $a->id,
            'time' => $a->created_at,
            'client' => $a->company->name,
            'amount' => (float) $a->amount,
            'reason' => $a->reason,
            'admin' => $a->admin->name,
        ]));
    }

    /**
     * Either move the balance by `amount` (signed), or set it to an exact
     * `set_balance`. Both end up as one audited adjustment row.
     */
    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'amount' => ['required_without:set_balance', 'prohibits:set_balance', 'numeric', 'not_in:0'],
            'set_balance' => ['required_without:amount', 'numeric', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $adjustment = isset($data['set_balance'])
            ? $this->billing->setWalletBalance($company, $request->user(), (float) $data['set_balance'], $data['reason'])
            : $this->billing->adjustWallet($company, $request->user(), (float) $data['amount'], $data['reason']);

        if (! $adjustment) {
            throw ValidationException::withMessages(['set_balance' => 'The balance is already that amount.']);
        }

        return response()->json($adjustment->load('company:id,name', 'admin:id,name'), 201);
    }
}
