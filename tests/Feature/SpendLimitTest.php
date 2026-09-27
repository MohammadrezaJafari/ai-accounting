<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppApiKey;
use App\Models\Organization;
use App\Models\UsageLog;
use App\Models\User;
use App\Notifications\SpendLimitAlert;
use App\Services\ApiKeyService;
use App\Services\WalletService;
use App\Support\BudgetPeriod;
use App\Support\Money;
use App\Support\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpendLimitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private App $budgetedApp;

    private AppApiKey $key;

    private string $plainKey;

    protected function setUp(): void
    {
        parent::setUp();
        // 12:00 on 15 Mehr 1405 in Tehran.
        $this->travelTo('2026-10-07 08:30:00');

        $this->organization = Organization::factory()->create();
        $this->budgetedApp = $this->organization->apps()->create(['name' => 'Bot']);
        $this->budgetedApp->forceFill(['balance' => Money::fromUsd('100')])->save();
        [$this->key, $this->plainKey] = app(ApiKeyService::class)->create($this->budgetedApp, ['name' => 'prod']);
    }

    private function charge(string $usd): void
    {
        app(WalletService::class)->chargeUsage($this->budgetedApp->refresh(), $this->key->refresh(), Money::fromUsd($usd));
    }

    public function test_monthly_app_limit_blocks_requests_until_the_next_jalali_month(): void
    {
        $this->budgetedApp->update(['spend_limit' => Money::fromUsd('10'), 'spend_limit_period' => BudgetPeriod::Monthly]);

        $this->charge('6');
        $this->withToken($this->plainKey)->getJson('/v1/models')->assertOk();

        $this->charge('4');
        $this->withToken($this->plainKey)->getJson('/v1/models')
            ->assertStatus(402)
            ->assertJsonPath('error.message', 'This app has reached its monthly spend limit.');

        // 1 Aban 1405 starts at 2026-10-22 20:30 UTC.
        $this->travelTo('2026-10-22 20:29:00');
        $this->withToken($this->plainKey)->getJson('/v1/models')->assertStatus(402);

        $this->travelTo('2026-10-22 20:31:00');
        $this->withToken($this->plainKey)->getJson('/v1/models')->assertOk();

        $this->charge('1');
        $this->assertSame('1.00', Money::toUsd($this->budgetedApp->refresh()->spentThisPeriod()));
        $this->assertSame('11.00', Money::toUsd($this->key->refresh()->spent));
    }

    public function test_daily_key_limit_resets_at_tehran_midnight(): void
    {
        $this->key->update(['spend_limit' => Money::fromUsd('2'), 'spend_limit_period' => BudgetPeriod::Daily]);

        $this->charge('2');
        $this->withToken($this->plainKey)->getJson('/v1/models')
            ->assertStatus(402)
            ->assertJsonPath('error.message', 'This API key has reached its daily spend limit.');

        $this->travelTo('2026-10-07 20:31:00'); // 00:01 in Tehran
        $this->withToken($this->plainKey)->getJson('/v1/models')->assertOk();
    }

    public function test_a_new_limit_counts_what_was_already_spent_this_period(): void
    {
        foreach (['2026-09-20 10:00:00' => '50', '2026-10-01 10:00:00' => '7'] as $at => $charge) {
            UsageLog::query()->forceCreate([
                'request_id' => Str::uuid(), 'app_id' => $this->budgetedApp->id, 'app_api_key_id' => $this->key->id,
                'endpoint' => 'chat/completions', 'model' => 'gpt', 'charge' => Money::fromUsd($charge), 'status_code' => 200, 'created_at' => $at,
            ]);
        }

        // Only the charge after 1 Mehr (2026-09-22 20:30 UTC) counts toward this month.
        $this->budgetedApp->update(['spend_limit' => Money::fromUsd('8')]);
        $this->assertSame('7.00', Money::toUsd($this->budgetedApp->refresh()->spentThisPeriod()));
        $this->assertFalse($this->budgetedApp->isOverSpendLimit());

        $this->budgetedApp->update(['spend_limit_period' => BudgetPeriod::Daily]);
        $this->assertSame(0, $this->budgetedApp->refresh()->spentThisPeriod());
    }

    public function test_owners_and_billing_are_alerted_once_at_80_and_100_percent(): void
    {
        Notification::fake();
        $owner = User::factory()->inOrganization($this->organization, OrganizationRole::Owner)->create();
        $billing = User::factory()->inOrganization($this->organization, OrganizationRole::Billing)->create();
        $developer = User::factory()->inOrganization($this->organization, OrganizationRole::Developer)->create();
        $this->budgetedApp->update(['spend_limit' => Money::fromUsd('10')]);

        $this->charge('5');
        Notification::assertNothingSent();

        $this->charge('3.5');
        $this->charge('0.5');
        Notification::assertSentTo([$owner, $billing], SpendLimitAlert::class, fn (SpendLimitAlert $alert) => $alert->level === 80);
        Notification::assertNotSentTo($developer, SpendLimitAlert::class);

        $this->charge('2');
        $this->charge('2');
        Notification::assertSentTo($owner, SpendLimitAlert::class, fn (SpendLimitAlert $alert) => $alert->level === 100);
        Notification::assertSentToTimes($owner, SpendLimitAlert::class, 2);

        // A new month starts over.
        $this->travelTo('2026-10-23 08:00:00');
        $this->charge('9');
        Notification::assertSentToTimes($owner, SpendLimitAlert::class, 3);
    }

    public function test_key_alerts_reach_developers_and_show_in_the_panel(): void
    {
        $developer = User::factory()->inOrganization($this->organization, OrganizationRole::Developer)->create();
        $this->key->update(['spend_limit' => Money::fromUsd('1')]);

        $this->charge('1');

        Sanctum::actingAs($developer);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.data.level', 100)
            ->assertJsonPath('data.0.data.subject', 'key')
            ->assertJsonPath('data.0.data.key_name', 'prod')
            ->assertJsonPath('data.0.data.title', 'سقف هزینهٔ کلید «prod» از اپ «Bot» پر شد');

        $this->postJson('/api/v1/notifications/read')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_app_resource_reports_the_current_period(): void
    {
        $this->budgetedApp->update(['spend_limit' => Money::fromUsd('10')]);
        $this->charge('2.5');

        Sanctum::actingAs(User::factory()->inOrganization($this->organization)->create());
        $this->getJson("/api/v1/apps/{$this->budgetedApp->id}")
            ->assertOk()
            ->assertJsonPath('data.spend_limit', '10.00')
            ->assertJsonPath('data.spend_limit_period', 'monthly')
            ->assertJsonPath('data.spent_this_period', '2.50')
            ->assertJsonPath('data.period_ends_at', '2026-10-22T20:30:00.000000Z');
    }
}
