<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agent product sold per unit (e.g. «گزارش»). `slug` selects its implementation,
 * `model` is the AI model it calls and `max_cost_per_run` caps what one run may spend (nano-USD).
 */
#[Fillable(['slug', 'name', 'description', 'unit_name', 'model', 'max_cost_per_run', 'is_active', 'sort_order'])]
class Agent extends Model
{
    public const NEWS_MONITOR = 'news-monitor';

    protected function casts(): array
    {
        return [
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
}
