<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->company->apiKeys()->whereNull('revoked_at')->latest()->get()
                ->map(fn (ApiKey $k) => [
                    'id' => $k->id,
                    'label' => $k->label,
                    'prefix' => $k->key_prefix.'…',
                    'created' => $k->created_at,
                ])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:255']]);

        [$key, $plaintext] = ApiKey::generate($request->user()->company, $data['label']);

        // The only time the plaintext key is ever returned — the frontend
        // must show it once and tell the client to store it themselves.
        return response()->json([
            'id' => $key->id,
            'label' => $key->label,
            'key' => $plaintext,
        ], 201);
    }

    public function destroy(Request $request, ApiKey $apiKey)
    {
        abort_unless($apiKey->company_id === $request->user()->company_id, 403);

        $apiKey->update(['revoked_at' => now()]);

        return response()->noContent();
    }
}
