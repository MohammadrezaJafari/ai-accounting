<?php

namespace App\Models;

use App\Models\Concerns\HasSpendLimit;
use App\Support\BudgetPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer application that consumes AI models through the gateway. It belongs to an
 * organization, has its own prepaid balance (nano-USD) and an optional daily / monthly spend limit.
 * `user_id` is the member who created it.
 */
#[Fillable(['organization_id', 'user_id', 'name', 'description', 'markup_bps', 'is_active', 'spend_limit', 'spend_limit_period'])]
class App extends Model
{
    use HasSpendLimit;

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'markup_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function defaultSpendLimitPeriod(): BudgetPeriod
    {
        return BudgetPeriod::Monthly;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
