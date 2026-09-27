<?php

namespace App\Models;

use App\Support\OrganizationPermission;
use App\Support\OrganizationRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'current_organization_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_CUSTOMER = 'customer';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() && $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Apps this user created (they belong to an organization).
     */
    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationMember::class)
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * The organization the customer panel is working in; resolved by the `organization` middleware.
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function currentRole(): ?OrganizationRole
    {
        return $this->currentOrganization?->roleOf($this);
    }

    public function canInCurrentOrganization(OrganizationPermission $permission): bool
    {
        return $this->currentRole()?->allows($permission) ?? false;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
