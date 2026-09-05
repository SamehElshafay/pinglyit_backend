<?php

namespace Tests\Feature\Commerce;

use App\Models\ApiKey;
use App\Models\Company;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferTest extends TestCase
{
    use RefreshDatabase;

    private function clientToken(Company $company): string
    {
        $user = $company->users()->create(['name' => 'Demo', 'email' => 'demo@example.test', 'password' => 'password']);

        return app(JwtService::class)->issue($user, 'user')['token'];
    }

    public function test_dashboard_can_create_a_store_wide_offer(): void
    {
        $company = Company::factory()->create();
        $token = $this->clientToken($company);

        $response = $this->withToken($token)->postJson('/api/offers', [
            'title' => 'Eid sale', 'discount_type' => 'percentage', 'discount_value' => 15,
            'expires_at' => now()->addWeek()->toDateTimeString(),
        ]);

        $response->assertCreated()->assertJsonPath('title', 'Eid sale');
        $this->assertDatabaseHas('offers', ['company_id' => $company->id, 'product_id' => null]);
    }

    public function test_an_offer_cannot_be_scoped_to_another_companys_product(): void
    {
        $otherCompany = Company::factory()->create();
        $product = $otherCompany->products()->create(['name' => 'Mug', 'price' => 5]);

        $company = Company::factory()->create();
        $token = $this->clientToken($company);

        $this->withToken($token)->postJson('/api/offers', [
            'title' => 'Sneaky', 'product_id' => $product->id, 'discount_type' => 'fixed', 'discount_value' => 2,
        ])->assertStatus(422);
    }

    public function test_expires_at_must_be_after_starts_at(): void
    {
        $company = Company::factory()->create();
        $token = $this->clientToken($company);

        $this->withToken($token)->postJson('/api/offers', [
            'title' => 'Backwards', 'discount_type' => 'fixed', 'discount_value' => 5,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'expires_at' => now()->toDateTimeString(),
        ])->assertStatus(422);
    }

    public function test_a_company_cannot_delete_another_companys_offer(): void
    {
        $owner = Company::factory()->create();
        $offer = $owner->offers()->create(['title' => 'X', 'discount_type' => 'fixed', 'discount_value' => 1]);

        $intruder = Company::factory()->create();
        $this->withToken($this->clientToken($intruder))->deleteJson("/api/offers/{$offer->id}")->assertStatus(403);
    }

    public function test_gateway_api_key_can_manage_its_own_offers(): void
    {
        $company = Company::factory()->create();
        [, $plaintext] = ApiKey::generate($company, 'Production');

        $this->withToken($plaintext)->postJson('/api/v1/offers', [
            'title' => 'Flash sale', 'discount_type' => 'percentage', 'discount_value' => 10,
        ])->assertCreated();

        $this->withToken($plaintext)->getJson('/api/v1/offers')->assertOk()->assertJsonCount(1);
    }
}
