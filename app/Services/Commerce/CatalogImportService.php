<?php

namespace App\Services\Commerce;

use App\Models\CatalogSource;
use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pulls a company's products from the company's own API.
 *
 * Most businesses already keep their catalog somewhere. Asking them to
 * retype it here is how it goes stale within a week — and a stale catalog
 * means the AI quotes a price that no longer exists to a real customer.
 *
 * The mapping is deliberately forgiving: a feed that calls the field
 * `title` or `product_name` instead of `name` imports without anyone
 * configuring anything (see CatalogSource::DEFAULT_FIELD_MAP). Explicit
 * mapping exists for the feeds that don't fit any of those.
 */
class CatalogImportService
{
    private const MAX_ITEMS = 2000;

    /**
     * Fetch and import. Returns a summary rather than throwing on a feed
     * problem — the caller is a person pressing a button who needs to be
     * told what went wrong, not a stack trace.
     *
     * @return array{ok: bool, imported: int, skipped: int, message: string}
     */
    public function sync(CatalogSource $source): array
    {
        try {
            $items = $this->fetchItems($source);
        } catch (RuntimeException $e) {
            $this->recordResult($source, 'failed', 0, $e->getMessage());

            return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'message' => $e->getMessage()];
        }

        $imported = 0;
        $skipped = 0;

        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            if (! is_array($item)) {
                $skipped++;

                continue;
            }

            $mapped = $this->mapItem($source, $item);

            // A product with no name or no price can't be quoted or sold, so
            // importing it would only give the AI something broken to offer.
            if (blank($mapped['name']) || $mapped['price'] === null) {
                $skipped++;

                continue;
            }

            // Keyed on SKU where the feed has one, so a re-sync updates the
            // same product instead of duplicating it. Falling back to the
            // name keeps that true for feeds without SKUs.
            $key = filled($mapped['sku'])
                ? ['company_id' => $source->company_id, 'sku' => $mapped['sku']]
                : ['company_id' => $source->company_id, 'name' => $mapped['name']];

            Product::updateOrCreate($key, $mapped + ['company_id' => $source->company_id]);
            $imported++;
        }

        $message = $skipped > 0
            ? "Imported {$imported} products. Skipped {$skipped} with no name or price."
            : "Imported {$imported} products.";

        $this->recordResult($source, 'ok', $imported, null);

        return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'message' => $message];
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchItems(CatalogSource $source): array
    {
        $headers = [];

        if (filled($source->auth_token)) {
            $prefix = filled($source->auth_prefix) ? $source->auth_prefix.' ' : '';
            $headers[$source->auth_header ?: 'Authorization'] = $prefix.$source->auth_token;
        }

        try {
            $request = Http::withHeaders($headers)->timeout(30)->acceptJson();

            $response = strtoupper($source->method) === 'POST'
                ? $request->post($source->url, $source->query_params ?? [])
                : $request->get($source->url, $source->query_params ?? []);
        } catch (ConnectionException $e) {
            Log::warning('Catalog source unreachable', ['company_id' => $source->company_id, 'reason' => $e->getMessage()]);

            throw new RuntimeException("Couldn't reach that URL. Check it's correct and reachable from the internet.");
        }

        if ($response->failed()) {
            throw new RuntimeException("That endpoint returned {$response->status()}. Check the URL and the token.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('That endpoint did not return JSON.');
        }

        // An explicit path wins; otherwise accept either a bare array or the
        // handful of wrapper keys almost every API uses.
        $items = filled($source->items_path)
            ? Arr::get($body, $source->items_path)
            : $this->guessItems($body);

        if (! is_array($items) || $items === []) {
            throw new RuntimeException(
                filled($source->items_path)
                    ? "No array of products found at \"{$source->items_path}\" in the response."
                    : 'Could not find a list of products in the response. Set the "items path" to point at it.'
            );
        }

        return array_values($items);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function guessItems(array $body): mixed
    {
        if (array_is_list($body)) {
            return $body;
        }

        foreach (['data', 'items', 'products', 'results', 'records'] as $key) {
            $candidate = $body[$key] ?? null;
            if (is_array($candidate) && array_is_list($candidate)) {
                return $candidate;
            }
            // One level deeper covers the common {data: {items: [...]}}.
            if (is_array($candidate)) {
                foreach (['items', 'products', 'data'] as $inner) {
                    if (isset($candidate[$inner]) && is_array($candidate[$inner]) && array_is_list($candidate[$inner])) {
                        return $candidate[$inner];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapItem(CatalogSource $source, array $item): array
    {
        $custom = $source->field_map ?? [];

        $pick = function (string $field) use ($item, $custom) {
            // An explicit mapping may point at a nested path.
            if (filled($custom[$field] ?? null)) {
                return Arr::get($item, $custom[$field]);
            }

            foreach (CatalogSource::DEFAULT_FIELD_MAP[$field] ?? [] as $candidate) {
                if (isset($item[$candidate]) && $item[$candidate] !== '') {
                    return $item[$candidate];
                }
            }

            return null;
        };

        $price = $pick('price');
        $active = $pick('active');

        return [
            'name' => is_scalar($pick('name')) ? (string) $pick('name') : null,
            // Strips currency symbols and thousands separators, because
            // plenty of feeds send "EGP 1,250.00" rather than a number.
            'price' => is_numeric($price) ? (float) $price : $this->parsePrice($price),
            'currency' => is_scalar($pick('currency')) ? strtoupper((string) $pick('currency')) : 'USD',
            'sku' => is_scalar($pick('sku')) ? (string) $pick('sku') : null,
            'description' => is_scalar($pick('description')) ? (string) $pick('description') : null,
            'image_url' => is_scalar($pick('image_url')) ? (string) $pick('image_url') : null,
            // Absent means available — a feed that doesn't say is listing
            // things it sells.
            'active' => $active === null ? true : filter_var($active, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function parsePrice(mixed $value): ?float
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $value));

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function recordResult(CatalogSource $source, string $status, int $count, ?string $error): void
    {
        $source->update([
            'last_synced_at' => now(),
            'last_status' => $status,
            'last_error' => $error,
            'last_imported_count' => $count,
        ]);
    }
}
