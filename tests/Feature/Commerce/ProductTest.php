<?php

namespace Tests\Feature\Commerce;

use App\Models\ApiKey;
use App\Models\Company;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI Commerce Assistant's catalog (AiCommerceAgentService), managed
 * two ways — dashboard (JWT) and the client's own API key — since "من خلال
 * الـ APIs او من الداشبورد" was the actual requirement. Exercised through
 * both /api/products (dashboard) and /api/v1/products (gateway).
 */
class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function clientToken(Company $company): string
    {
        $user = $company->users()->create(['name' => 'Demo', 'email' => 'demo@example.test', 'password' => 'password']);

        return app(JwtService::class)->issue($user, 'user')['token'];
    }

    private function apiKey(Company $company): string
    {
        [, $plaintext] = ApiKey::generate($company, 'Production');

        return $plaintext;
    }

    public function test_dashboard_can_create_list_update_and_delete_a_product(): void
    {
        $company = Company::factory()->create();
        $token = $this->clientToken($company);

        $create = $this->withToken($token)->postJson('/api/products', [
            'name' => 'T-Shirt', 'price' => 19.99, 'currency' => 'USD',
        ]);
        $create->assertCreated()->assertJsonPath('name', 'T-Shirt');
        $productId = $create->json('id');

        $this->withToken($token)->getJson('/api/products')->assertOk()->assertJsonCount(1);

        $this->withToken($token)->putJson("/api/products/{$productId}", ['price' => 24.99])
            ->assertOk()->assertJsonPath('price', '24.9900');

        $this->withToken($token)->deleteJson("/api/products/{$productId}")->assertNoContent();
        $this->assertDatabaseMissing('products', ['id' => $productId]);
    }

    public function test_gateway_api_key_can_manage_its_own_products(): void
    {
        $company = Company::factory()->create();
        $key = $this->apiKey($company);

        $create = $this->withToken($key)->postJson('/api/v1/products', ['name' => 'Mug', 'price' => 9.5]);
        $create->assertCreated();

        $this->withToken($key)->getJson('/api/v1/products')->assertOk()->assertJsonCount(1);
    }

    public function test_a_company_cannot_edit_another_companys_product(): void
    {
        $owner = Company::factory()->create();
        $product = $owner->products()->create(['name' => 'Mug', 'price' => 9.5]);

        $intruder = Company::factory()->create();
        $token = $this->clientToken($intruder);

        $this->withToken($token)->putJson("/api/products/{$product->id}", ['price' => 1])->assertStatus(403);
        $this->withToken($token)->deleteJson("/api/products/{$product->id}")->assertStatus(403);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'price' => 9.5000]);
    }

    public function test_creating_a_product_requires_a_name_and_a_price(): void
    {
        $company = Company::factory()->create();
        $token = $this->clientToken($company);

        $this->withToken($token)->postJson('/api/products', [])->assertStatus(422);
    }

    public function test_effective_price_applies_the_best_active_offer(): void
    {
        $company = Company::factory()->create();
        $product = $company->products()->create(['name' => 'Shoes', 'price' => 100]);

        $this->assertSame(100.0, $product->effectivePrice());

        $company->offers()->create([
            'product_id' => $product->id, 'title' => '20% off', 'discount_type' => 'percentage',
            'discount_value' => 20, 'active' => true,
        ]);
        $this->assertSame(80.0, $product->fresh()->effectivePrice());

        // A store-wide fixed offer that's actually a bigger discount wins.
        $company->offers()->create([
            'product_id' => null, 'title' => '$50 off everything', 'discount_type' => 'fixed',
            'discount_value' => 50, 'active' => true,
        ]);
        $this->assertSame(50.0, $product->fresh()->effectivePrice());
    }

    public function test_an_expired_offer_no_longer_applies(): void
    {
        $company = Company::factory()->create();
        $product = $company->products()->create(['name' => 'Shoes', 'price' => 100]);
        $company->offers()->create([
            'product_id' => $product->id, 'title' => 'Expired', 'discount_type' => 'percentage',
            'discount_value' => 50, 'active' => true, 'expires_at' => now()->subDay(),
        ]);

        $this->assertSame(100.0, $product->fresh()->effectivePrice());
    }
}
