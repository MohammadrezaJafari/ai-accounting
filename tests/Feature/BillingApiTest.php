<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_register_create_app_and_key(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ali', 'email' => 'ali@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->json('token');

        $appId = $this->withToken($token)->postJson('/api/v1/apps', ['name' => 'Chatbot'])->assertCreated()->json('data.id');

        $response = $this->withToken($token)
            ->postJson("/api/v1/apps/{$appId}/keys", ['name' => 'prod', 'allowed_providers' => ['anthropic']])
            ->assertCreated();

        $this->assertStringStartsWith('sk-aia-', $response->json('plain_key'));
        $this->assertSame(['anthropic'], $response->json('data.allowed_providers'));
    }

    public function test_package_order_stays_pending_until_approved(): void
    {
        $user = User::factory()->inOrganization()->create();
        $app = $user->currentOrganization->apps()->create(['name' => 'App']);
        $package = Package::query()->where('name', 'سازمانی')->sole();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/orders', ['app_id' => $app->id, 'package_id' => $package->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.credit', '110.00');

        $this->assertSame(0, $app->refresh()->balance);
    }

    public function test_custom_amount_top_up_respects_limits(): void
    {
        config(['billing.payment_gateway' => 'fake']);
        $user = User::factory()->inOrganization()->create();
        $app = $user->currentOrganization->apps()->create(['name' => 'App']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', ['app_id' => $app->id, 'amount' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/orders', ['app_id' => $app->id, 'amount' => '37.5'])->assertCreated()->assertJsonPath('data.status', 'paid');

        $this->assertSame('37.50', Money::toUsd($app->refresh()->balance));
        $this->assertSame(Order::TYPE_CUSTOM, Order::query()->sole()->type);
    }

    public function test_users_cannot_touch_other_users_apps(): void
    {
        $other = Organization::factory()->create()->apps()->create(['name' => 'Theirs']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/apps/{$other->id}")->assertNotFound();
        $this->postJson('/api/v1/orders', ['app_id' => $other->id, 'amount' => 10])->assertNotFound();
    }

    public function test_customer_catalog_hides_cost(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $model = $this->getJson('/api/v1/catalog/models')->assertOk()->json('data.0');
        $this->assertArrayHasKey('price', $model);
        $this->assertArrayNotHasKey('cost', $model);
        $this->assertArrayNotHasKey('upstream_id', $model);
    }
}
