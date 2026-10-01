<?php

namespace App\Support;

/**
 * A member's role in an organization.
 *
 * - Owner: everything, including members and the organization itself.
 * - Developer: apps, API keys (and their spend limits), the panel chat and the organization's marketplace agents.
 * - Billing: wallets, top-ups, orders, app spend limits and publisher payout details.
 * - Member: read-only (what every member sees); the Hub's member and reader roles land here.
 *
 * Every member can see apps, usage and logs.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Developer = 'developer';
    case Billing = 'billing';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالک',
            self::Developer => 'توسعه‌دهنده',
            self::Billing => 'مالی',
            self::Member => 'عضو',
        };
    }

    public function allows(OrganizationPermission $permission): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Developer => in_array($permission, [OrganizationPermission::ManageApps, OrganizationPermission::ManageKeys, OrganizationPermission::UseChat, OrganizationPermission::PublishAgents], true),
            self::Billing => $permission === OrganizationPermission::ManageBilling,
            self::Member => false,
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
