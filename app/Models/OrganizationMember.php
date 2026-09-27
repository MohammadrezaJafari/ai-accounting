<?php

namespace App\Models;

use App\Support\OrganizationRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership (and role) in an organization.
 */
class OrganizationMember extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
        ];
    }
}
