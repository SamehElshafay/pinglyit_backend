<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Same resource as Client\ProductController, managed via the company's own
 * API key instead of a dashboard session — "من خلال الـ APIs او من
 * الداشبورد" was the actual product requirement, so both need to exist.
 * Not shared code with the Client controller: company resolution differs
 * ($request->attributes->get('company') here vs $request->user()->company
 * there), same as the rest of this codebase's Gateway\* / Client\* pairs
 * (e.g. Gateway\WhatsAppController vs Client\ServiceController).
 */
class ProductController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->attributes->get('company')->products()->latest()->get());
    }

    public function store(Request $request)
    {
        $company = $request->attributes->get('company');
        $product = $company->products()->create($this->validated($request));

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product)
    {
        abort_unless($product->company_id === $request->attributes->get('company')->id, 403);

        $product->update($this->validated($request, $product));

        return response()->json($product->fresh());
    }

    public function destroy(Request $request, Product $product)
    {
        abort_unless($product->company_id === $request->attributes->get('company')->id, 403);

        $product->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sku' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
