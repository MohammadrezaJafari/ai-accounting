<?php

namespace App\Support;

/**
 * What a member of an organization may do. Each case is also registered as a gate.
 */
enum OrganizationPermission: string
{
    case ManageApps = 'manage-apps';
    case ManageKeys = 'manage-keys';
    case ManageBilling = 'manage-billing';
    case ManageMembers = 'manage-members';
    case UseChat = 'use-chat';
    case PublishAgents = 'publish-agents';
}
