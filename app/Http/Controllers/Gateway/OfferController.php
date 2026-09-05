<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfferController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->attributes->get('company')->offers()->latest()->get());
    }

    public function store(Request $request)
    {
        $company = $request->attributes->get('company');
        $offer = $company->offers()->create($this->validated($request, $company->id));

        return response()->json($offer, 201);
    }

    public function update(Request $request, Offer $offer)
    {
        $company = $request->attributes->get('company');
        abort_unless($offer->company_id === $company->id, 403);

        $offer->update($this->validated($request, $company->id, $offer));

        return response()->json($offer->fresh());
    }

    public function destroy(Request $request, Offer $offer)
    {
        abort_unless($offer->company_id === $request->attributes->get('company')->id, 403);

        $offer->delete();

        return response()->noContent();
    }

    private function validated(Request $request, int $companyId, ?Offer $offer = null): array
    {
        return $request->validate([
            'title' => [$offer ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'discount_type' => [$offer ? 'sometimes' : 'required', 'string', 'in:percentage,fixed'],
            'discount_value' => [$offer ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
