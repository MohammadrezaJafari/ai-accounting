<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\Oidc\OidcClient;
use App\Support\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps organizations and their members in step with the Rahap Hub (ADR-0004/0005).
 *
 * The wallet is one per tenant, so a tenant maps to ONE organization: its holding, or its root
 * company when it has no holding. Companies under the holding and workspaces are acknowledged
 * and ignored. The Hub is the authority on who is in the organization and with which role,
 * except that the organization never loses its last owner.
 */
class ProvisioningService
{
    public const ROLES = [
        'admin' => OrganizationRole::Owner,
        'manager' => OrganizationRole::Developer,
        'member' => OrganizationRole::Member,
        'reader' => OrganizationRole::Member,
    ];

    public function __construct(private OidcClient $oidc) {}

    /**
     * Whether this product keeps an organization for the Hub organization at all.
     */
    public function keeps(string $kind, ?string $parent): bool
    {
        return $kind === 'holding' || ($kind === 'company' && blank($parent));
    }

    /**
     * Create or rename the tenant's organization; null when the tenant already has another one.
     */
    public function upsertOrganization(string $tenant, string $key, string $name): ?Organization
    {
        return DB::transaction(function () use ($tenant, $key, $name) {
            $organization = Organization::query()->where('tenant', $tenant)->where('external_key', $key)->lockForUpdate()->first();

            if ($organization) {
                $organization->update(['name' => $name]);

                return $organization;
            }

            if (Organization::query()->where('tenant', $tenant)->exists()) {
                return null;
            }

            return Organization::query()->create(['tenant' => $tenant, 'external_key' => $key, 'name' => $name]);
        });
    }

    public function find(string $tenant, string $key): ?Organization
    {
        return Organization::query()->where('tenant', $tenant)->where('external_key', $key)->first();
    }

    /**
     * Whether members sent for an organization this product does not keep should just be acknowledged:
     * a workspace (`org/ws`) or a company of a tenant whose organization already exists.
     */
    public function ignoresMembersOf(string $tenant, string $key): bool
    {
        return str_contains($key, '/') || Organization::query()->where('tenant', $tenant)->exists();
    }

    public static function slug(string $key): string
    {
        return str_replace('/', '--', $key);
    }

    /**
     * Replace the organization's members with `$members`.
     *
     * @param  list<array{sub?: string|null, email: string, name?: string|null, role: string}>  $members
     * @return array{added: int, changed: int, removed: int, skipped: int}
     */
    public function syncMembers(Organization $organization, array $members): array
    {
        $issuer = $this->oidc->enabled() ? $this->oidc->issuerFor($organization->tenant) : null;

        return DB::transaction(function () use ($organization, $members, $issuer) {
            $wanted = [];
            $skipped = 0;

            foreach ($members as $member) {
                $user = $this->userFor($member, $issuer);

                if (! $user) {
                    $skipped++;

                    continue;
                }

                $role = self::ROLES[$member['role']];
                $current = $wanted[$user->id] ?? null;
                $wanted[$user->id] = $current && $this->rank($current) > $this->rank($role) ? $current : $role;
            }

            $existing = $organization->members()->get()->mapWithKeys(fn (User $user) => [$user->id => $user->pivot->role])->all();

            // The organization never loses its last owner: without an admin from the Hub, its owners stay.
            if (! in_array(OrganizationRole::Owner, $wanted, true)) {
                foreach ($existing as $userId => $role) {
                    if ($role === OrganizationRole::Owner) {
                        $wanted[$userId] = OrganizationRole::Owner;
                    }
                }
            }

            $counts = ['added' => 0, 'changed' => 0, 'removed' => 0, 'skipped' => $skipped];

            foreach ($wanted as $userId => $role) {
                if (! array_key_exists($userId, $existing)) {
                    $organization->members()->attach($userId, ['role' => $role->value]);
                    $counts['added']++;
                } elseif ($existing[$userId] !== $role) {
                    $organization->members()->updateExistingPivot($userId, ['role' => $role->value]);
                    $counts['changed']++;
                }
            }

            $removed = array_diff(array_keys($existing), array_keys($wanted));

            if ($removed !== []) {
                $organization->members()->detach($removed);
                User::query()->whereIn('id', $removed)->where('current_organization_id', $organization->id)->update(['current_organization_id' => null]);
                $counts['removed'] = count($removed);
            }

            return $counts;
        });
    }

    /**
     * The user for a Hub member: by (issuer, subject), then by email; created when unknown.
     * Null when the email belongs to an identity of another tenant (never joined across tenants).
     *
     * @param  array{sub?: string|null, email: string, name?: string|null}  $member
     */
    private function userFor(array $member, ?string $issuer): ?User
    {
        $subject = filled($member['sub'] ?? null) ? (string) $member['sub'] : null;
        $email = Str::lower(trim($member['email']));

        $user = $subject
            ? User::query()->where('oidc_subject', $subject)->where(fn ($query) => $issuer ? $query->where('oidc_issuer', $issuer) : $query->whereNull('oidc_issuer'))->first()
            : null;

        $user ??= User::query()->where('email', $email)->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => filled($member['name'] ?? null) ? $member['name'] : Str::before($email, '@'),
                'email' => $email,
                // Never usable: provisioned users sign in through their identity provider.
                'password' => Str::random(64),
                'role' => User::ROLE_CUSTOMER,
            ]);
            $user->forceFill(['oidc_issuer' => $subject ? $issuer : null, 'oidc_subject' => $subject])->save();

            return $user;
        }

        if ($user->oidc_issuer !== null && $issuer !== null && $user->oidc_issuer !== $issuer) {
            return null;
        }

        if ($subject && $user->oidc_subject === null) {
            $user->forceFill(['oidc_issuer' => $issuer, 'oidc_subject' => $subject])->save();
        }

        return $user;
    }

    private function rank(OrganizationRole $role): int
    {
        return match ($role) {
            OrganizationRole::Owner => 3,
            OrganizationRole::Developer => 2,
            OrganizationRole::Billing => 1,
            OrganizationRole::Member => 0,
        };
    }
}
