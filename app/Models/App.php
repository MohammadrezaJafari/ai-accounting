<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer application that consumes AI models through the gateway.
 * Each app has its own prepaid balance (nano-USD).
 */
#[Fillable(['user_id', 'name', 'description', 'markup_bps', 'is_active'])]
class App extends Model
{
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'markup_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(AppApiKey::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
