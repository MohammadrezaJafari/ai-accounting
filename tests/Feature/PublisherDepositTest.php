<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\PublisherPayout;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Money;
use App\Support\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublisherDepositTest extends TestCase
{
    use RefreshDatabase;

    private Organization $publisher;

    private App $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = Organization::factory()->create();
        $this->wallet = $this->walletOf($this->publisher, 'اپ اصلی');

        // Model costs above its share: $8 below zero, past the $5 limit for test runs.
        PublisherPayout::query()->create(['organization_id' => $this->publisher->id, 'amount' => Money::fromUsd('8'), 'paid_at' => now()]);
        Sanctum::actingAs(User::factory()->inOrganization($this->publisher, OrganizationRole::Billing)->create());
    }

    private function walletOf(Organization $organization, string $name): App
    {
        $app = $organization->apps()->create(['name' => $name]);
        $app->forceFill(['balance' => Money::fromUsd('20')])->save();

        return $app;
    }

    public function test_a_deposit_raises_the_balance_and_reopens_test_runs(): void
    {
        $this->getJson('/api/v1/publisher')
            ->assertJsonPath('summary.balance', '-8.00')
            ->assertJsonPath('test_runs_blocked', true)
            ->assertJsonPath('payout_min', '10.00')
            ->assertJsonPath('payout_due', false);

        $this->postJson('/api/v1/publisher/deposits', ['app_id' => $this->wallet->id, 'amount' => '6'])->assertCreated()
            ->assertJsonPath('data.type', PublisherPayout::TYPE_DEPOSIT)
            ->assertJsonPath('data.amount', '6.00');

        $this->getJson('/api/v1/publisher')->assertOk()
            ->assertJsonPath('summary.paid', '8.00')
            ->assertJsonPath('summary.deposits', '6.00')
            ->assertJsonPath('summary.balance', '-2.00')
            ->assertJsonPath('test_runs_blocked', false)
            ->assertJsonPath('payouts.0.type', PublisherPayout::TYPE_DEPOSIT)
            ->assertJsonPath('payouts.0.app.name', 'اپ اصلی');

        $this->assertSame(Money::fromUsd('14'), $this->wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'app_id' => $this->wallet->id,
            'type' => WalletTransaction::TYPE_PUBLISHER_DEPOSIT,
            'amount' => -Money::fromUsd('6'),
            'balance_after' => Money::fromUsd('14'),
        ]);
    }

    public function test_a_deposit_above_the_wallet_balance_is_rejected(): void
    {
        $this->postJson('/api/v1/publisher/deposits', ['app_id' => $this->wallet->id, 'amount' => '25'])->assertJsonValidationErrors('amount');

        $this->assertSame(Money::fromUsd('20'), $this->wallet->fresh()->balance);
        $this->assertSame(0, PublisherPayout::query()->where('type', PublisherPayout::TYPE_DEPOSIT)->count());
    }

    public function test_an_app_of_another_organization_is_rejected(): void
    {
        $other = $this->walletOf(Organization::factory()->create(), 'دیگری');

        $this->postJson('/api/v1/publisher/deposits', ['app_id' => $other->id, 'amount' => '5'])->assertJsonValidationErrors('app_id');

        $this->assertSame(Money::fromUsd('20'), $other->fresh()->balance);
    }

    public function test_amounts_outside_the_limits_are_rejected(): void
    {
        $this->postJson('/api/v1/publisher/deposits', ['app_id' => $this->wallet->id, 'amount' => '0.5'])->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/publisher/deposits', ['app_id' => $this->wallet->id, 'amount' => '5000'])->assertJsonValidationErrors('amount');
    }

    public function test_a_developer_may_not_deposit(): void
    {
        Sanctum::actingAs(User::factory()->inOrganization($this->publisher, OrganizationRole::Developer)->create());

        $this->postJson('/api/v1/publisher/deposits', ['app_id' => $this->wallet->id, 'amount' => '5'])->assertForbidden();
    }
}
