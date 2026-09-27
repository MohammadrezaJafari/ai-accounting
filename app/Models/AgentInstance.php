<?php

namespace App\Models;

use App\Services\Agents\AgentSchedule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An organization's configured agent (e.g. one news monitor with its sources and keywords).
 * Model calls are logged under `app_id`; `run_hours` are hours of the day (Tehran) to run at,
 * empty = manual runs only, on `run_days` (0 = Sunday … 6 = Saturday; empty = every day); `state` is the agent's memory between runs (e.g. news already seen).
 */
#[Fillable(['organization_id', 'agent_id', 'app_id', 'name', 'config', 'run_hours', 'run_days', 'state', 'is_active', 'last_run_at', 'next_run_at', 'created_by'])]
class AgentInstance extends Model
{
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (self $instance) {
            if ($instance->isDirty(['run_hours', 'run_days', 'is_active']) || ! $instance->exists) {
                $instance->next_run_at = $instance->is_active ? AgentSchedule::next($instance->run_hours ?? [], $instance->run_days ?? []) : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'run_hours' => 'array',
            'run_days' => 'array',
            'state' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(AgentDestination::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(AgentRun::class)->latestOfMany();
    }

    public function hasRunInProgress(): bool
    {
        return $this->runs()
            ->whereIn('status', [AgentRun::STATUS_QUEUED, AgentRun::STATUS_RUNNING])
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();
    }
}
