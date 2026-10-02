<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CatalogSource;
use App\Services\Commerce\CatalogImportService;
use Illuminate\Http\Request;

/**
 * Connect a company's own product API so the catalog stays in step with
 * the system they already run their business from — see
 * CatalogImportService for why that matters more than it sounds.
 */
class CatalogSourceController extends Controller
{
    public function __construct(private readonly CatalogImportService $importer) {}

    public function show(Request $request)
    {
        $source = $request->user()->company->catalogSource;

        return response()->json($source ? $this->present($source) : ['configured' => false]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'method' => ['sometimes', 'string', 'in:GET,POST'],
            // Absent leaves the stored token alone, so saving an unrelated
            // change doesn't require retyping a credential that's already
            // been masked out of the response.
            'auth_token' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'auth_header' => ['sometimes', 'nullable', 'string', 'max:64'],
            'auth_prefix' => ['sometimes', 'nullable', 'string', 'max:32'],
            'query_params' => ['sometimes', 'nullable', 'array'],
            'items_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'field_map' => ['sometimes', 'nullable', 'array'],
            'auto_sync' => ['sometimes', 'boolean'],
        ]);

        $company = $request->user()->company;

        if (! array_key_exists('auth_token', $data)) {
            unset($data['auth_token']);
        }

        $source = CatalogSource::updateOrCreate(
            ['company_id' => $company->id],
            $data + ['company_id' => $company->id],
        );

        return response()->json($this->present($source->fresh()));
    }

    /** Fetch now and report what happened, in words the client can act on. */
    public function sync(Request $request)
    {
        $source = $request->user()->company->catalogSource;

        if (! $source) {
            return response()->json(['message' => 'No product API is connected yet.'], 422);
        }

        $result = $this->importer->sync($source);

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'source' => $this->present($source->fresh()),
        ], $result['ok'] ? 200 : 422);
    }

    public function destroy(Request $request)
    {
        $request->user()->company->catalogSource?->delete();

        return response()->json(['configured' => false]);
    }

    /** @return array<string, mixed> */
    private function present(CatalogSource $source): array
    {
        return [
            'configured' => true,
            'url' => $source->url,
            'method' => $source->method,
            // Never the token itself — only whether one is held, same rule
            // as every other secret on the platform.
            'has_token' => filled($source->auth_token),
            'auth_header' => $source->auth_header,
            'auth_prefix' => $source->auth_prefix,
            'query_params' => $source->query_params,
            'items_path' => $source->items_path,
            'field_map' => $source->field_map,
            'auto_sync' => $source->auto_sync,
            'last_synced_at' => $source->last_synced_at,
            'last_status' => $source->last_status,
            'last_error' => $source->last_error,
            'last_imported_count' => $source->last_imported_count,
        ];
    }
}
