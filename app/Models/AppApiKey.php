<?php

namespace App\Models;

use App\Models\Concerns\HasSpendLimit;
use App\Support\BudgetPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A key issued to an app for calling the gateway. Only the SHA-256 hash is stored.
 * `allowed_providers` / `allowed_models` (null = unrestricted) scope a key to e.g. only Claude models.
 * `spent` is the lifetime total; the spend limit applies per `spend_limit_period`.
 */
#[Fillable(['app_id', 'name', 'key_prefix', 'key_hash', 'allowed_providers', 'allowed_models', 'spend_limit', 'spend_limit_period', 'expires_at', 'is_active'])]
#[Hidden(['key_hash'])]
class AppApiKey extends Model
{
    use HasSpendLimit;

    /** Key the panel chat bills through; created on first use. */
    public const PLAYGROUND_NAME = 'چت پنل';

    protected function casts(): array
    {
        return [
            'allowed_providers' => 'array',
            'allowed_models' => 'array',
            'spent' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected function defaultSpendLimitPeriod(): BudgetPeriod
    {
        return BudgetPeriod::Total;
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function allowsModel(AiModel $model): bool
    {
        if ($this->allowed_providers && ! in_array($model->provider->slug, $this->allowed_providers, true)) {
            return false;
        }

        return ! $this->allowed_models || in_array($model->public_id, $this->allowed_models, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
