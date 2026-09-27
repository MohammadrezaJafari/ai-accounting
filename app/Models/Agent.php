<?php

namespace App\Models;

use App\Support\AgentDriver;
use App\Support\AgentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 *
 * A listing made by a publisher organization goes through review (`status`); once approved,
 * its changes wait in `pending_changes` until the admin applies them. Its publisher pays for
 * the model calls and sets `max_cost_per_run` itself, up to `costCeiling()`.
 */
#[Fillable([
    'publisher_organization_id', 'slug', 'status', 'pending_changes', 'review_note', 'submitted_at', 'reviewed_at', 'driver', 'endpoint_url', 'timeout_seconds', 'run_deadline_minutes', 'config_schema',
    'name', 'tagline', 'icon', 'category', 'publisher_name', 'publisher_url', 'revenue_share',
    'description', 'unit_name', 'max_units_per_run', 'model', 'allowed_models', 'max_cost_per_run', 'max_cost_ceiling', 'is_active', 'sort_order',
])]
#[Hidden(['signing_secret'])]
class Agent extends Model
{
    public const NEWS_MONITOR = 'news-monitor';

    /** Fields a publisher may change; `packages` is handled alongside them. */
    public const PUBLISHER_FIELDS = [
        'name', 'tagline', 'description', 'icon', 'category', 'unit_name', 'max_units_per_run',
        'endpoint_url', 'timeout_seconds', 'run_deadline_minutes', 'config_schema',
    ];

    protected $attributes = ['driver' => 'http', 'status' => 'approved', 'max_units_per_run' => 1, 'timeout_seconds' => 60, 'run_deadline_minutes' => 15];

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
            'status' => AgentStatus::class,
            'pending_changes' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'signing_secret' => 'encrypted',
            'config_schema' => 'array',
            'allowed_models' => 'array',
            'timeout_seconds' => 'integer',
            'run_deadline_minutes' => 'integer',
            'max_units_per_run' => 'integer',
            'revenue_share' => 'integer',
            'max_cost_per_run' => 'integer',
            'max_cost_ceiling' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'publisher_organization_id');
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
        $query->where('is_active', true)->where('status', AgentStatus::Approved);
    }

    /**
     * The name shown as the publisher: the publisher organization's public name, or the one typed by the admin.
     */
    public function publisherDisplayName(): ?string
    {
        if ($this->publisher_organization_id) {
            return $this->publisher?->publisher_name ?: $this->publisher?->name;
        }

        return $this->publisher_name;
    }

    /**
     * The highest `max_cost_per_run` the publisher may set (nano-USD).
     */
    public function costCeiling(): int
    {
        return $this->max_cost_ceiling ?? Money::fromUsd(config('billing.publishers.max_cost_ceiling_usd'));
    }

    /**
     * Models the agent may call through the platform: the allowed list, or any available model when empty.
     */
    public function allowsModel(string $publicId): bool
    {
        return empty($this->allowed_models) || in_array($publicId, $this->allowed_models, true) || $publicId === $this->model;
    }
}
