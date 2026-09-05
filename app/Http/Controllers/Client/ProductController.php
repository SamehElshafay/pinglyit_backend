<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * The AI Commerce Assistant's catalog (see AiCommerceAgentService), managed
 * from the dashboard. Gateway\ProductController is the same resource
 * managed via the client's own API key instead — see its docblock for why
 * this isn't shared code with that one.
 */
class ProductController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->company->products()->latest()->get());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $product = $request->user()->company->products()->create($data);

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product)
    {
        abort_unless($product->company_id === $request->user()->company_id, 403);

        $product->update($this->validated($request, $product));

        return response()->json($product->fresh());
    }

    public function destroy(Request $request, Product $product)
    {
        abort_unless($product->company_id === $request->user()->company_id, 403);

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
