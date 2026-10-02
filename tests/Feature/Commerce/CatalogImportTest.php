<?php

namespace Tests\Feature\Commerce;

use App\Models\CatalogSource;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Commerce\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Importing a company's catalog from their own API.
 *
 * The forgiving field matching is the point, not a nicety: a client who has
 * to describe their JSON shape before anything works mostly doesn't finish,
 * and a catalog that's never imported means the AI has nothing to sell.
 */
class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: string} */
    private function client(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return [$company, app(JwtService::class)->issue($user, 'user')['token']];
    }

    private function source(Company $company, array $overrides = []): CatalogSource
    {
        return CatalogSource::create(array_merge([
            'company_id' => $company->id,
            'url' => 'https://shop.example.com/api/products',
            'method' => 'GET',
        ], $overrides));
    }

    public function test_a_bare_json_array_imports_with_no_configuration(): void
    {
        [$company] = $this->client();

        Http::fake(['shop.example.com/*' => Http::response([
            ['name' => 'T-Shirt', 'price' => 19.99, 'sku' => 'TS1'],
            ['name' => 'Mug', 'price' => 7.5, 'sku' => 'MG1'],
        ], 200)]);

        $result = app(CatalogImportService::class)->sync($this->source($company));

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(19.99, (float) Product::where('sku', 'TS1')->first()->price);
    }

    /** Different vocabularies for the same fields, which every feed has. */
    public function test_alternative_field_names_are_matched_automatically(): void
    {
        [$company] = $this->client();

        Http::fake(['shop.example.com/*' => Http::response(['data' => [
            ['title' => 'Chair', 'unit_price' => 120, 'item_code' => 'CH9', 'is_active' => true],
        ]], 200)]);

        $result = app(CatalogImportService::class)->sync($this->source($company));

        $this->assertSame(1, $result['imported']);
        $product = Product::where('sku', 'CH9')->firstOrFail();
        $this->assertSame('Chair', $product->name);
        $this->assertSame(120.0, (float) $product->price);
        $this->assertTrue((bool) $product->active);
    }

    public function test_a_price_written_as_a_formatted_string_is_still_imported(): void
    {
        [$company] = $this->client();

        Http::fake(['shop.example.com/*' => Http::response([
            ['name' => 'Desk', 'price' => 'EGP 1,250.00', 'sku' => 'DK1'],
        ], 200)]);

        app(CatalogImportService::class)->sync($this->source($company));

        $this->assertSame(1250.0, (float) Product::where('sku', 'DK1')->first()->price);
    }

    public function test_an_explicit_items_path_and_field_map_are_used(): void
    {
        [$company] = $this->client();

        Http::fake(['shop.example.com/*' => Http::response([
            'payload' => ['catalogue' => [
                ['label' => 'Lamp', 'cost' => ['value' => 45], 'ref' => 'LP3'],
            ]],
        ], 200)]);

        $result = app(CatalogImportService::class)->sync($this->source($company, [
            'items_path' => 'payload.catalogue',
            'field_map' => ['name' => 'label', 'price' => 'cost.value', 'sku' => 'ref'],
        ]));

        $this->assertSame(1, $result['imported']);
        $this->assertSame('Lamp', Product::where('sku', 'LP3')->first()->name);
    }

    public function test_resyncing_updates_products_instead_of_duplicating_them(): void
    {
        [$company] = $this->client();
        $source = $this->source($company);

        Http::fake(['shop.example.com/*' => Http::sequence()
            ->push([['name' => 'Mug', 'price' => 7.5, 'sku' => 'MG1']], 200)
            ->push([['name' => 'Mug', 'price' => 9.0, 'sku' => 'MG1']], 200)]);

        app(CatalogImportService::class)->sync($source);
        app(CatalogImportService::class)->sync($source);

        $this->assertSame(1, Product::where('company_id', $company->id)->count());
        $this->assertSame(9.0, (float) Product::where('sku', 'MG1')->first()->price);
    }

    public function test_items_with_no_name_or_price_are_skipped_not_imported_broken(): void
    {
        [$company] = $this->client();

        Http::fake(['shop.example.com/*' => Http::response([
            ['name' => 'Good', 'price' => 5],
            ['name' => 'No price'],
            ['price' => 10],
        ], 200)]);

        $result = app(CatalogImportService::class)->sync($this->source($company));

        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        $this->assertStringContainsString('Skipped 2', $result['message']);
    }

    public function test_a_failing_endpoint_reports_a_usable_message_and_is_recorded(): void
    {
        [$company] = $this->client();
        $source = $this->source($company);

        Http::fake(['shop.example.com/*' => Http::response(['error' => 'nope'], 401)]);

        $result = app(CatalogImportService::class)->sync($source);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('401', $result['message']);
        $this->assertSame('failed', $source->fresh()->last_status);
    }

    public function test_the_token_is_sent_as_configured_and_never_returned(): void
    {
        [$company, $jwt] = $this->client();

        Http::fake(['shop.example.com/*' => Http::response([['name' => 'X', 'price' => 1]], 200)]);

        $response = $this->withToken($jwt)->putJson('/api/catalog-source', [
            'url' => 'https://shop.example.com/api/products',
            'auth_token' => 'secret-token-value',
            'auth_header' => 'X-API-Key',
            'auth_prefix' => '',
            'query_params' => ['limit' => 100, 'status' => 'active'],
        ])->assertOk();

        $this->assertStringNotContainsString('secret-token-value', $response->getContent());
        $this->assertTrue($response->json('has_token'));

        $this->withToken($jwt)->postJson('/api/catalog-source/sync')->assertOk();

        Http::assertSent(function ($request) {
            return $request->header('X-API-Key')[0] === 'secret-token-value'
                && str_contains($request->url(), 'limit=100')
                && str_contains($request->url(), 'status=active');
        });
    }
}
