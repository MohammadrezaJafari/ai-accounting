<?php

namespace App\Models;

use App\Support\AgentDriver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A marketplace listing sold per unit (e.g. «گزارش»). An HTTP agent is a separate service at
 * `endpoint_url` that receives signed run requests (see HttpAgent); a built-in one is
 * implemented here and chosen by `slug`. `config_schema` defines what customers fill in.
 *
 * `model` is the default AI model, `allowed_models` those the agent may call (empty = any),
 * `max_cost_per_run` caps one run's model spend (nano-USD), `max_units_per_run` how many
 * units one run may use, and `revenue_share` the publisher's percent of the revenue.
 */
#[Fillable([
    'slug', 'driver', 'endpoint_url', 'timeout_seconds', 'run_deadline_minutes', 'config_schema',
    'name', 'tagline', 'icon', 'category', 'publisher_name', 'publisher_url', 'revenue_share',
    'description', 'unit_name', 'max_units_per_run', 'model', 'allowed_models', 'max_cost_per_run', 'is_active', 'sort_order',
])]
#[Hidden(['signing_secret'])]
class Agent extends Model
{
    public const NEWS_MONITOR = 'news-monitor';

    protected $attributes = ['driver' => 'http', 'max_units_per_run' => 1, 'timeout_seconds' => 60, 'run_deadline_minutes' => 15];

    protected static function booted(): void
    {
        static::creating(function (self $agent) {
            $agent->signing_secret ??= self::newSigningSecret();
        });
    }

    public static function newSigningSecret(): string
    {
        return 'ags_'.Str::random(40);
    }

    protected function casts(): array
    {
        return [
            'driver' => AgentDriver::class,
            'signing_secret' => 'encrypted',
            'config_schema' => 'array',
            'allowed_models' => 'array',
            'timeout_seconds' => 'integer',
            'run_deadline_minutes' => 'integer',
            'max_units_per_run' => 'integer',
            'revenue_share' => 'integer',
            'max_cost_per_run' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(AgentPackage::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(AgentInstance::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Models the agent may call through the platform: the allowed list, or any available model when empty.
     */
    public function allowsModel(string $publicId): bool
    {
        return empty($this->allowed_models) || in_array($publicId, $this->allowed_models, true) || $publicId === $this->model;
    }
}
