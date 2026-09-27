<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\OrganizationRole;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private App $supportBot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);

        $this->organization = Organization::factory()->create(['name' => 'Acme']);
        $this->supportBot = $this->organization->apps()->create(['name' => 'Support bot']);
    }

    private function member(OrganizationRole $role): User
    {
        return User::factory()->inOrganization($this->organization, $role)->create();
    }

    public function test_registration_creates_an_owned_organization(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sara', 'email' => 'sara@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'organization' => 'Sara Co',
        ])
            ->assertCreated()
            ->assertJsonPath('organization.name', 'Sara Co')
            ->assertJsonPath('organization.role', 'owner')
            ->assertJsonCount(1, 'organizations');

        Sanctum::actingAs(User::query()->where('email', 'sara@example.com')->sole());
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'sara@example.com')
            ->assertJsonPath('organization.permissions', ['manage-apps', 'manage-keys', 'manage-billing', 'manage-members', 'use-chat']);
    }

    public function test_customers_without_an_organization_get_a_personal_one(): void
    {
        Sanctum::actingAs($user = User::factory()->create(['name' => 'Reza']));

        $this->getJson('/api/v1/apps')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('Reza', $user->refresh()->currentOrganization->name);
        $this->assertSame(OrganizationRole::Owner, $user->currentRole());
    }

    public function test_members_share_the_organizations_apps_and_outsiders_do_not(): void
    {
        Sanctum::actingAs($this->member(OrganizationRole::Developer));
        $this->getJson('/api/v1/apps')->assertOk()->assertJsonPath('data.0.name', 'Support bot');
        $this->getJson("/api/v1/apps/{$this->supportBot->id}")->assertOk();

        Sanctum::actingAs(User::factory()->inOrganization()->create());
        $this->getJson('/api/v1/apps')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/apps/{$this->supportBot->id}")->assertNotFound();
    }

    public function test_developers_manage_apps_and_keys_but_not_billing(): void
    {
        Sanctum::actingAs($this->member(OrganizationRole::Developer));

        $this->postJson('/api/v1/apps', ['name' => 'New'])->assertCreated();
        $this->postJson("/api/v1/apps/{$this->supportBot->id}/keys", ['name' => 'prod', 'spend_limit' => '20', 'spend_limit_period' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('data.spend_limit_period', 'monthly');

        $this->postJson('/api/v1/orders', ['app_id' => $this->supportBot->id, 'amount' => '10'])->assertForbidden();
        $this->getJson('/api/v1/orders')->assertForbidden();
        $this->getJson("/api/v1/apps/{$this->supportBot->id}/transactions")->assertForbidden();
        $this->patchJson("/api/v1/apps/{$this->supportBot->id}", ['spend_limit' => '100'])->assertForbidden();
        $this->postJson('/api/v1/organization/invitations', ['email' => 'x@example.com', 'role' => 'developer'])->assertForbidden();
    }

    public function test_billing_members_manage_money_but_not_keys(): void
    {
        Sanctum::actingAs($this->member(OrganizationRole::Billing));

        $this->patchJson("/api/v1/apps/{$this->supportBot->id}", ['spend_limit' => '100', 'spend_limit_period' => 'daily'])
            ->assertOk()
            ->assertJsonPath('data.spend_limit', '100.00')
            ->assertJsonPath('data.spend_limit_period', 'daily');
        $this->postJson('/api/v1/orders', ['app_id' => $this->supportBot->id, 'amount' => '10'])->assertCreated();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/keys')->assertForbidden();
        $this->postJson("/api/v1/apps/{$this->supportBot->id}/keys", ['name' => 'prod'])->assertForbidden();
        $this->postJson('/api/v1/apps', ['name' => 'New'])->assertForbidden();
        $this->patchJson("/api/v1/apps/{$this->supportBot->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->postJson("/api/v1/apps/{$this->supportBot->id}/chat/completions", ['model' => 'gpt-4o-mini', 'messages' => []])->assertForbidden();
    }

    public function test_an_app_limit_cannot_be_a_lifetime_limit(): void
    {
        Sanctum::actingAs($this->member(OrganizationRole::Owner));

        $this->patchJson("/api/v1/apps/{$this->supportBot->id}", ['spend_limit' => '5', 'spend_limit_period' => 'total'])
            ->assertJsonValidationErrors('spend_limit_period');
    }

    public function test_invited_user_joins_through_the_link(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->member(OrganizationRole::Owner));

        $url = $this->postJson('/api/v1/organization/invitations', ['email' => 'Ali@Example.com', 'role' => 'billing'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ali@example.com')
            ->assertJsonPath('data.role_label', 'مالی')
            ->json('data.url');
        $token = OrganizationInvitation::query()->sole()->token;
        $this->assertStringEndsWith("/invite/{$token}", $url);

        Notification::assertSentOnDemand(
            OrganizationInvitationNotification::class,
            fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'ali@example.com',
        );

        Sanctum::actingAs(User::factory()->inOrganization()->create(['email' => 'someone@example.com']));
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertJsonValidationErrors('token');

        Sanctum::actingAs($ali = User::factory()->create(['email' => 'ali@example.com']));
        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.organization.name', 'Acme')->assertJsonMissingPath('data.url');
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertOk()->assertJsonPath('data.name', 'Acme')->assertJsonPath('data.role', 'billing');

        $this->assertSame(OrganizationRole::Billing, $this->organization->roleOf($ali));
        $this->assertSame($this->organization->id, $ali->refresh()->current_organization_id);
        $this->assertSame(0, OrganizationInvitation::query()->count());
        $this->getJson('/api/v1/organizations')->assertJsonCount(2, 'data');
    }

    public function test_expired_invitations_cannot_be_accepted(): void
    {
        $invitation = $this->organization->invitations()->create([
            'email' => 'late@example.com', 'role' => 'developer', 'token' => 'expired-token', 'expires_at' => now()->subDay(),
        ]);

        Sanctum::actingAs(User::factory()->create(['email' => 'late@example.com']));
        $this->postJson("/api/v1/invitations/{$invitation->token}/accept")->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/invitations/unknown/accept')->assertNotFound();
    }

    public function test_an_organization_keeps_at_least_one_owner(): void
    {
        $owner = $this->member(OrganizationRole::Owner);
        $developer = $this->member(OrganizationRole::Developer);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/organization/members/{$owner->id}", ['role' => 'developer'])->assertJsonValidationErrors('role');
        $this->deleteJson("/api/v1/organization/members/{$owner->id}")->assertJsonValidationErrors('role');

        $this->patchJson("/api/v1/organization/members/{$developer->id}", ['role' => 'owner'])->assertOk()->assertJsonPath('data.role', 'owner');
        $this->deleteJson("/api/v1/organization/members/{$owner->id}")->assertOk();

        $this->assertNull($this->organization->roleOf($owner));
        $this->assertNull($owner->refresh()->current_organization_id);
    }

    public function test_members_can_leave_but_not_remove_others(): void
    {
        $developer = $this->member(OrganizationRole::Developer);
        $billing = $this->member(OrganizationRole::Billing);
        Sanctum::actingAs($developer);

        $this->getJson('/api/v1/organization/members')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/organization/members/{$billing->id}")->assertForbidden();
        $this->deleteJson("/api/v1/organization/members/{$developer->id}")->assertOk();
    }

    public function test_users_switch_only_between_their_own_organizations(): void
    {
        $user = $this->member(OrganizationRole::Developer);
        $other = Organization::factory()->create();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/organizations', ['name' => 'Side project'])->assertCreated()->json('data.id');
        $this->assertSame($created, $user->refresh()->current_organization_id);

        $this->postJson("/api/v1/organizations/{$this->organization->id}/switch")->assertOk();
        $this->assertSame($this->organization->id, $user->refresh()->current_organization_id);

        $this->postJson("/api/v1/organizations/{$other->id}/switch")->assertNotFound();
    }
}
