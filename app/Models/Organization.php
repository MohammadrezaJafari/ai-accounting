<?php

namespace App\Models;

use App\Support\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer account (a company or a person) that owns apps and has members with roles.
 */
#[Fillable(['name'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationMember::class)
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    public function agentInstances(): HasMany
    {
        return $this->hasMany(AgentInstance::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function roleOf(User $user): ?OrganizationRole
    {
        return OrganizationMember::query()
            ->where('organization_id', $this->id)
            ->where('user_id', $user->id)
            ->first(['role'])
            ?->role;
    }

    /**
     * Members holding any of the given roles.
     *
     * @param  list<OrganizationRole>  $roles
     */
    public function membersWithRole(array $roles): BelongsToMany
    {
        return $this->members()->wherePivotIn('role', array_map(fn (OrganizationRole $role) => $role->value, $roles));
    }
}
