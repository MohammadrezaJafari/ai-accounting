<?php

namespace App\Support;

/**
 * A member's role in an organization.
 *
 * - Owner: everything, including members and the organization itself.
 * - Developer: apps, API keys (and their spend limits) and the panel chat.
 * - Billing: wallets, top-ups, orders and app spend limits.
 *
 * Every member can see apps, usage and logs.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Developer = 'developer';
    case Billing = 'billing';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالک',
            self::Developer => 'توسعه‌دهنده',
            self::Billing => 'مالی',
        };
    }

    public function allows(OrganizationPermission $permission): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Developer => in_array($permission, [OrganizationPermission::ManageApps, OrganizationPermission::ManageKeys, OrganizationPermission::UseChat], true),
            self::Billing => $permission === OrganizationPermission::ManageBilling,
        };
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_map(
            fn (OrganizationPermission $permission) => $permission->value,
            array_filter(OrganizationPermission::cases(), fn (OrganizationPermission $permission) => $this->allows($permission)),
        ));
    }
}
