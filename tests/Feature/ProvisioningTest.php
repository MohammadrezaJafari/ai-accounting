<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'hub-service-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['oidc.service_key' => self::KEY]);
    }

    public function test_without_a_service_key_the_endpoints_do_not_exist(): void
    {
        config(['oidc.service_key' => null]);

        $this->withToken('anything')->putJson('/api/service/v1/organizations/alpha', $this->holding())
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        $this->withToken('')->putJson('/api/service/v1/members', [])->assertNotFound();
        $this->assertSame(0, Organization::query()->count());
    }

    public function test_a_wrong_key_is_unauthenticated_and_every_answer_has_a_request_id(): void
    {
        $this->withToken('wrong')->putJson('/api/service/v1/organizations/alpha', $this->holding(), ['X-Request-Id' => 'req-1'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonPath('request_id', 'req-1')
            ->assertHeader('X-Request-Id', 'req-1');

        $this->assertNotEmpty($this->putOrganization('alpha', $this->holding())->headers->get('X-Request-Id'));
    }

    public function test_the_tenants_holding_becomes_one_organization_idempotently(): void
    {
        $response = $this->putOrganization('alpha', $this->holding())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('slug', 'alpha');

        $organization = Organization::query()->sole();
        $this->assertSame((string) $organization->id, $response->json('id'));
        $this->assertSame(['alpha', 'alpha', 'Alpha Holding'], [$organization->tenant, $organization->external_key, $organization->name]);

        $this->putOrganization('alpha', ['name' => 'Alpha Group'] + $this->holding())->assertOk()->assertJsonPath('id', (string) $organization->id);
        $this->assertSame('Alpha Group', Organization::query()->sole()->name);
    }

    public function test_companies_under_the_holding_and_workspaces_are_ignored(): void
    {
        $this->putOrganization('alpha', $this->holding())->assertOk();

        $this->putOrganization('bank', ['tenant' => 'alpha', 'kind' => 'company', 'name' => 'Bank', 'parent' => 'alpha'])
            ->assertStatus(202)
            ->assertExactJson(['ignored' => true]);
        $this->putOrganization('alpha%2Fbank', ['tenant' => 'alpha', 'kind' => 'workspace', 'name' => 'Bank WS', 'parent' => 'alpha'])
            ->assertStatus(202);

        $this->assertSame(1, Organization::query()->count());
    }

    public function test_a_root_company_counts_and_a_tenant_never_gets_a_second_organization(): void
    {
        $this->putOrganization('solo', ['tenant' => 'solo', 'kind' => 'company', 'name' => 'Solo'])->assertOk();
        $this->putOrganization('other', ['tenant' => 'solo', 'kind' => 'holding', 'name' => 'Other'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'conflict');

        // The same key in another tenant is another organization.
        $this->putOrganization('solo', ['tenant' => 'beta', 'kind' => 'company', 'name' => 'Solo'])->assertOk();
        $this->assertSame(2, Organization::query()->count());
    }

    public function test_invalid_payloads_answer_with_the_contracts_error_shape(): void
    {
        $this->putOrganization('alpha', ['tenant' => 'alpha', 'kind' => 'team'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation')
            ->assertJsonStructure(['code', 'message', 'errors' => ['kind', 'name'], 'request_id']);

        $this->putMembers(['tenant' => 'alpha', 'organization' => 'alpha', 'members' => [['email' => 'x', 'role' => 'boss']]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation');
    }

    public function test_members_are_created_with_mapped_roles(): void
    {
        $organization = $this->provisioned();

        $this->putMembers($this->members([
            ['sub' => 'sub-sara', 'email' => 'Sara@Alpha.test', 'name' => 'سارا', 'role' => 'admin', 'unit' => 'sales', 'kind' => 'employee'],
            ['sub' => 'sub-reza', 'email' => 'reza@alpha.test', 'name' => 'Reza', 'role' => 'manager'],
            ['sub' => 'sub-mina', 'email' => 'mina@alpha.test', 'role' => 'member'],
            ['email' => 'neda@alpha.test', 'role' => 'reader'],
        ]))->assertOk()->assertExactJson(['ok' => true, 'added' => 4, 'changed' => 0, 'removed' => 0, 'skipped' => 0]);

        $sara = User::query()->where('email', 'sara@alpha.test')->sole();
        $this->assertSame(['سارا', 'sub-sara', 'customer', true], [$sara->name, $sara->oidc_subject, $sara->role, $sara->is_active]);
        $this->assertSame(OrganizationRole::Owner, $organization->roleOf($sara));
        $this->assertSame(OrganizationRole::Developer, $organization->roleOf(User::query()->where('email', 'reza@alpha.test')->sole()));
        $this->assertSame(OrganizationRole::Member, $organization->roleOf(User::query()->where('email', 'mina@alpha.test')->sole()));
        $this->assertSame(OrganizationRole::Member, $organization->roleOf(User::query()->where('email', 'neda@alpha.test')->sole()));

        // Members are read-only: they see the organization but may change nothing.
        Sanctum::actingAs(User::query()->where('email', 'mina@alpha.test')->sole());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('organization.role', 'member')->assertJsonPath('organization.permissions', []);
        $this->postJson('/api/v1/apps', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_the_list_replaces_the_membership_and_is_idempotent(): void
    {
        $organization = $this->provisioned();
        $this->putMembers($this->members([
            ['sub' => 'sub-sara', 'email' => 'sara@alpha.test', 'role' => 'admin'],
            ['sub' => 'sub-reza', 'email' => 'reza@alpha.test', 'role' => 'admin'],
            ['sub' => 'sub-mina', 'email' => 'mina@alpha.test', 'role' => 'manager'],
        ]))->assertOk();
        $this->putMembers($this->members([
            ['sub' => 'sub-sara', 'email' => 'sara@alpha.test', 'role' => 'admin'],
            ['sub' => 'sub-reza', 'email' => 'reza@alpha.test', 'role' => 'admin'],
            ['sub' => 'sub-mina', 'email' => 'mina@alpha.test', 'role' => 'manager'],
        ]))->assertExactJson(['ok' => true, 'added' => 0, 'changed' => 0, 'removed' => 0, 'skipped' => 0]);

        $mina = User::query()->where('email', 'mina@alpha.test')->sole();
        $mina->forceFill(['current_organization_id' => $organization->id])->save();

        // A role raised by hand in the product is replaced by the Hub's.
        $organization->members()->updateExistingPivot($mina->id, ['role' => OrganizationRole::Owner->value]);

        $this->putMembers($this->members([
            ['sub' => 'sub-sara', 'email' => 'sara@alpha.test', 'role' => 'admin'],
            ['sub' => 'sub-reza', 'email' => 'reza@alpha.test', 'role' => 'reader'],
        ]))->assertExactJson(['ok' => true, 'added' => 0, 'changed' => 1, 'removed' => 1, 'skipped' => 0]);

        $this->assertSame(OrganizationRole::Member, $organization->roleOf(User::query()->where('email', 'reza@alpha.test')->sole()));
        $this->assertNull($organization->roleOf($mina));
        $this->assertNull($mina->refresh()->current_organization_id);
        $this->assertSame(3, User::query()->count(), 'Removed members keep their account.');
    }

    public function test_the_last_owner_is_never_removed_or_demoted(): void
    {
        $organization = $this->provisioned();
        $owner = User::factory()->inOrganization($organization, OrganizationRole::Owner)->create(['email' => 'owner@alpha.test']);

        $this->putMembers($this->members([['sub' => 'sub-mina', 'email' => 'mina@alpha.test', 'role' => 'member']]))
            ->assertOk()
            ->assertJsonPath('removed', 0);
        $this->assertSame(OrganizationRole::Owner, $organization->roleOf($owner));

        $this->putMembers($this->members([['sub' => 'sub-owner', 'email' => 'owner@alpha.test', 'role' => 'manager']]))->assertOk();
        $this->assertSame(OrganizationRole::Owner, $organization->roleOf($owner->refresh()));

        $this->putMembers($this->members([]))->assertOk();
        $this->assertSame([$owner->id], $organization->members()->pluck('users.id')->all());

        // Once the Hub names an admin, the old owner follows the list like everyone else.
        $this->putMembers($this->members([['sub' => 'sub-sara', 'email' => 'sara@alpha.test', 'role' => 'admin']]))
            ->assertOk()
            ->assertJsonPath('removed', 1);
        $this->assertNull($organization->roleOf($owner));
    }

    public function test_unknown_organizations_are_not_found_and_ignored_ones_are_acknowledged(): void
    {
        $this->putMembers(['tenant' => 'alpha', 'organization' => 'alpha', 'members' => []])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->provisioned();
        $this->putMembers(['tenant' => 'alpha', 'organization' => 'bank', 'members' => []])->assertStatus(202)->assertJsonPath('ignored', true);
        $this->putMembers(['tenant' => 'alpha', 'organization' => 'alpha/bank', 'members' => []])->assertStatus(202);
        // The same key in another tenant is not this organization.
        $this->putMembers(['tenant' => 'beta', 'organization' => 'alpha', 'members' => []])->assertNotFound();
    }

    public function test_provisioned_members_are_the_users_their_first_oidc_sign_in_reaches(): void
    {
        config(['oidc.issuer' => 'https://sso.test/realms/{tenant}']);
        $organization = $this->provisioned();

        $this->putMembers($this->members([['sub' => 'sub-sara', 'email' => 'sara@alpha.test', 'role' => 'admin']]))->assertOk();

        $sara = User::query()->sole();
        $this->assertSame(['https://sso.test/realms/alpha', 'sub-sara'], [$sara->oidc_issuer, $sara->oidc_subject]);
        $this->assertSame(OrganizationRole::Owner, $organization->roleOf($sara));
    }

    public function test_a_user_of_another_tenant_is_never_added(): void
    {
        config(['oidc.issuer' => 'https://sso.test/realms/{tenant}']);
        $organization = $this->provisioned();
        $beta = User::factory()->create(['email' => 'sara@alpha.test']);
        $beta->forceFill(['oidc_issuer' => 'https://sso.test/realms/beta', 'oidc_subject' => 'beta-sara'])->save();

        $this->putMembers($this->members([['sub' => 'alpha-sara', 'email' => 'sara@alpha.test', 'role' => 'admin']]))
            ->assertOk()
            ->assertJsonPath('added', 0)
            ->assertJsonPath('skipped', 1);
        $this->assertNull($organization->roleOf($beta));
    }

    private function provisioned(): Organization
    {
        $this->putOrganization('alpha', $this->holding())->assertOk();

        return Organization::query()->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function holding(): array
    {
        return ['tenant' => 'alpha', 'kind' => 'holding', 'name' => 'Alpha Holding', 'parent' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function members(array $members): array
    {
        return ['tenant' => 'alpha', 'organization' => 'alpha', 'members' => $members];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putOrganization(string $key, array $payload): TestResponse
    {
        return $this->withToken(self::KEY)->putJson('/api/service/v1/organizations/'.$key, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putMembers(array $payload): TestResponse
    {
        return $this->withToken(self::KEY)->putJson('/api/service/v1/members', $payload);
    }
}
