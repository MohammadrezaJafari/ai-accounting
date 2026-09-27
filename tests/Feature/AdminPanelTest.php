<?php

namespace Tests\Feature;

use App\Filament\Pages\BillingSettings;
use App\Filament\Resources\Agents\Pages\CreateAgent;
use App\Filament\Resources\Agents\Pages\EditAgent;
use App\Filament\Resources\AiModels\Pages\CreateAiModel;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Publishers\Pages\ListPublishers;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\PublisherReviewNotification;
use App\Services\OrderService;
use App\Services\SettingsService;
use App\Support\AgentDriver;
use App\Support\AgentStatus;
use App\Support\Money;
use App\Support\OrganizationRole;
use Database\Seeders\AgentSeeder;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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
        $app = Organization::factory()->create()->apps()->create(['name' => 'App']);
        $provider = Provider::query()->firstOrFail();
        $this->seed(AgentSeeder::class);
        $agent = Agent::query()->firstOrFail();

        $this->actingAs($this->admin);

        foreach (['/admin', '/admin/providers', "/admin/providers/{$provider->id}/edit", '/admin/models', '/admin/packages',
            '/admin/users', '/admin/apps', "/admin/apps/{$app->id}/edit", '/admin/orders', '/admin/usage', '/admin/billing-settings',
            '/admin/organizations', "/admin/organizations/{$app->organization_id}/edit", '/admin/agents', "/admin/agents/{$agent->id}/edit", '/admin/publishers'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_lists_an_external_agent_with_its_parameters(): void
    {
        $this->actingAs($this->admin);
        $undoRepeaterFake = Repeater::fake();
        $base = [
            'name' => 'پایش رقبا', 'slug' => 'competitor-watch', 'driver' => 'http', 'endpoint_url' => 'https://agent.test/run',
            'unit_name' => 'گزارش', 'max_units_per_run' => 3, 'revenue_share' => 30,
        ];

        Livewire::test(CreateAgent::class)
            ->fillForm($base + ['config_schema' => [
                ['key' => 'domain', 'label' => 'دامنه', 'type' => 'url', 'required' => true],
                ['key' => 'domain', 'label' => 'تکراری', 'type' => 'text'],
            ]])
            ->call('create')
            ->assertHasFormErrors(['config_schema']);

        Livewire::test(CreateAgent::class)
            ->fillForm($base + ['config_schema' => [
                ['key' => 'domain', 'label' => 'دامنه', 'type' => 'url', 'required' => true],
                ['key' => 'depth', 'label' => 'عمق', 'type' => 'select', 'default' => 'quick', 'options' => [['value' => 'quick', 'label' => 'سریع'], ['value' => 'deep', 'label' => 'کامل']]],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();
        $agent = Agent::query()->where('slug', 'competitor-watch')->sole();
        $this->assertSame(AgentDriver::Http, $agent->driver);
        $this->assertStringStartsWith('ags_', $agent->signing_secret);
        $this->assertSame(['domain', 'depth'], array_column($agent->config_schema, 'key'));
        $this->assertSame('quick', $agent->config_schema[1]['default']);

        Http::fake([
            'agent.test/run' => Http::response(['ok' => true]),
            'agent.test/manifest.json' => Http::response([
                'name' => 'رصد رقبا', 'tagline' => 'قیمت و محصولات رقبا را هر روز بررسی می‌کند',
                'publisher' => ['name' => 'استودیو نوآ', 'url' => 'https://noa.test'],
                'config_schema' => [['key' => 'competitors', 'label' => 'رقبا', 'type' => 'tags', 'required' => true]],
            ]),
        ]);

        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])
            ->callAction('ping')
            ->assertNotified('سرویس ایجنت پاسخ داد.')
            ->callAction('importManifest', data: ['url' => 'https://agent.test/manifest.json'])
            ->assertNotified('مشخصات ایجنت از manifest به‌روز شد.')
            ->assertSchemaStateSet(['name' => 'رصد رقبا']);

        $agent->refresh();
        $this->assertSame(['استودیو نوآ', ['competitors']], [$agent->publisher_name, array_column($agent->config_schema, 'key')]);

        $secret = $agent->signing_secret;
        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])->callAction('rotateSecret');
        $this->assertNotSame($secret, $agent->refresh()->signing_secret);
    }

    public function test_admin_reviews_a_publisher_listing_and_records_a_payout(): void
    {
        Notification::fake();
        $publisher = Organization::factory()->create(['name' => 'استودیو نوآ']);
        User::factory()->inOrganization($publisher)->create();
        $agent = Agent::query()->create([
            'publisher_organization_id' => $publisher->id, 'slug' => 'lead-finder', 'status' => AgentStatus::PendingReview,
            'name' => 'یابندهٔ مشتری', 'unit_name' => 'مشتری', 'endpoint_url' => 'https://agent.noa.test/run', 'is_active' => false,
            'revenue_share' => 70, 'pending_changes' => ['tagline' => 'معرفی'],
        ]);
        $this->actingAs($this->admin);

        $this->get('/admin/agents')->assertOk()->assertSee('در انتظار بررسی');

        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])
            ->callAction('approve', data: ['revenue_share' => 65])
            ->assertHasNoActionErrors();

        $agent->refresh();
        $this->assertSame([AgentStatus::Approved, true, 65, 'معرفی', null], [$agent->status, $agent->is_active, $agent->revenue_share, $agent->tagline, $agent->pending_changes]);
        Notification::assertSentTo($publisher->members()->first(), PublisherReviewNotification::class);

        $agent->update(['pending_changes' => ['name' => 'نام تازه']]);
        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])
            ->assertActionVisible('applyChanges')
            ->callAction('reject', data: ['note' => 'نام مناسب نیست'])
            ->assertHasNoActionErrors();
        $this->assertSame(['یابندهٔ مشتری', null, 'نام مناسب نیست'], [$agent->refresh()->name, $agent->pending_changes, $agent->review_note]);

        $this->get('/admin/publishers')->assertOk()->assertSee('استودیو نوآ');
        Livewire::test(ListPublishers::class)
            ->callAction(TestAction::make('recordPayout')->table($publisher), data: ['amount' => '12.5', 'paid_at' => now()->toDateTimeString(), 'reference' => 'TX-9'])
            ->assertHasNoFormErrors();
        $this->assertSame([Money::fromUsd('12.5'), 'TX-9', $this->admin->id], [$publisher->payouts()->sole()->amount, $publisher->payouts()->sole()->reference, $publisher->payouts()->sole()->created_by]);
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
        $user = User::factory()->inOrganization()->create();
        $app = $user->currentOrganization->apps()->create(['name' => 'App']);
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
        $app = Organization::factory()->create()->apps()->create(['name' => 'App']);

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

    public function test_admin_manages_organization_members(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->inOrganization($organization)->create();
        $newcomer = User::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $organization, 'pageClass' => EditOrganization::class])
            ->assertCanSeeTableRecords([$owner])
            ->assertTableColumnFormattedStateSet('pivot.role', 'مالک', $owner)
            ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $newcomer->id, 'role' => 'billing'])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('edit')->table($newcomer), data: ['role' => 'developer'])
            ->assertHasNoFormErrors();

        $this->assertSame(OrganizationRole::Developer, $organization->roleOf($newcomer));
        $this->assertSame(User::ROLE_CUSTOMER, $newcomer->refresh()->role);
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
