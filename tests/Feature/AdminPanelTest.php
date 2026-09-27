<?php

namespace Tests\Feature;

use App\Filament\Pages\BillingSettings;
use App\Filament\Resources\AiModels\Pages\CreateAiModel;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\AiModel;
use App\Models\Order;
use App\Models\Provider;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SettingsService;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_customers_cannot_open_the_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/models')->assertForbidden();
    }

    public function test_admin_can_open_every_page(): void
    {
        $app = User::factory()->create()->apps()->create(['name' => 'App']);
        $provider = Provider::query()->firstOrFail();

        $this->actingAs($this->admin);

        foreach (['/admin', '/admin/providers', "/admin/providers/{$provider->id}/edit", '/admin/models', '/admin/packages',
            '/admin/users', '/admin/apps', "/admin/apps/{$app->id}/edit", '/admin/orders', '/admin/usage', '/admin/billing-settings'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_model_prices_are_entered_in_usd(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAiModel::class)
            ->fillForm([
                'provider_id' => Provider::query()->where('slug', 'openai')->value('id'),
                'name' => 'New model',
                'public_id' => 'new-model',
                'upstream_id' => 'new-model-2026',
                'input_price' => '1.5',
                'output_price' => '6',
                'markup_bps' => '30',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $model = AiModel::query()->where('public_id', 'new-model')->sole();
        $this->assertSame(Money::fromUsd('1.5'), $model->input_price);
        $this->assertSame(3000, $model->markup_bps);
        $this->assertNull($model->cached_input_price);
    }

    public function test_admin_approves_a_pending_order(): void
    {
        $user = User::factory()->create();
        $app = $user->apps()->create(['name' => 'App']);
        $order = app(OrderService::class)->forCustomAmount($user, $app, Money::fromUsd('50'));

        $this->actingAs($this->admin);

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('markPaid')->table($order), data: ['gateway_ref' => 'BANK-123'])
            ->assertHasNoFormErrors();

        $this->assertSame(Order::STATUS_PAID, $order->refresh()->status);
        $this->assertSame('BANK-123', $order->gateway_ref);
        $this->assertSame('50.00', Money::toUsd($app->refresh()->balance));
    }

    public function test_admin_adjusts_app_balance(): void
    {
        $app = User::factory()->create()->apps()->create(['name' => 'App']);

        $this->actingAs($this->admin);

        Livewire::test(ListApps::class)
            ->callAction(TestAction::make('adjustBalance')->table($app), data: ['amount' => '12.5', 'type' => 'adjustment', 'description' => 'gift'])
            ->assertHasNoFormErrors();

        $this->assertSame('12.50', Money::toUsd($app->refresh()->balance));
        $this->assertSame($this->admin->id, $app->transactions()->sole()->created_by);

        Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
            ->fillForm(['markup_bps' => '5'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(500, $app->refresh()->markup_bps);
    }

    public function test_billing_settings_are_saved(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(BillingSettings::class)
            ->fillForm(['default_markup_bps' => '35', 'custom_topup_min' => '2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SettingsService::class)->all();
        $this->assertSame(3500, $settings['default_markup_bps']);
        $this->assertSame(Money::fromUsd('2'), $settings['custom_topup_min']);
    }
}
