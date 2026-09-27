<?php

namespace App\Models;

use App\Support\OrganizationRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending invitation to join an organization. It is accepted through a link carrying
 * `token`, by a signed-in user whose email matches `email`.
 */
#[Fillable(['organization_id', 'email', 'role', 'token', 'invited_by', 'expires_at'])]
#[Hidden(['token'])]
class OrganizationInvitation extends Model
{
    public const VALID_DAYS = 7;

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function url(): string
    {
        return rtrim(config('billing.panel_url'), '/').'/invite/'.$this->token;
    }
}
