<?php

namespace App\Http\Resources;

use App\Services\Agents\ConfigSchema;
use App\Services\Publishers\PublisherEarnings;
use App\Services\Publishers\PublisherService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing as its publisher sees it: everything it may edit, the review state, the terms
 * (revenue share, model access, its own cost cap and the ceiling for it) and the signing
 * secret its service checks requests with. `stats` is set by the controller when requested.
 */
class PublisherAgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_live' => $this->status->value === 'approved' && $this->is_active,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'icon' => $this->icon,
            'category' => $this->category,
            'kind' => $this->kind?->value ?? 'report',
            'unit_name' => $this->unit_name,
            'max_units_per_run' => $this->max_units_per_run,
            'endpoint_url' => $this->endpoint_url,
            'timeout_seconds' => $this->timeout_seconds,
            'run_deadline_minutes' => $this->run_deadline_minutes,
            'config_schema' => ConfigSchema::for($this->resource)->fields(),
            'packages' => app(PublisherService::class)->currentPackages($this->resource),
            'pending_changes' => $this->pending_changes,
            'review_note' => $this->review_note,
            'submitted_at' => $this->submitted_at,
            'reviewed_at' => $this->reviewed_at,
            'signing_secret' => $this->signing_secret,
            'terms' => [
                'revenue_share' => $this->revenue_share,
                'max_cost_per_run' => $this->max_cost_per_run === null ? null : Money::toUsd($this->max_cost_per_run),
                'max_cost_ceiling' => Money::toUsd($this->costCeiling()),
                'test_run_debt_limit' => Money::toUsd(Money::fromUsd(config('billing.publishers.test_run_debt_limit_usd'))),
                'test_runs_blocked' => $this->publisher && app(PublisherEarnings::class)->testRunsBlocked($this->publisher),
                'default_model' => $this->model,
                'allowed_models' => $this->allowed_models ?? [],
            ],
            'stats' => $this->when(isset($this->stats), fn () => [
                ...$this->stats,
                'revenue' => Money::toUsd($this->stats['revenue']),
                'share' => Money::toUsd($this->stats['share']),
                'earned' => Money::toUsd($this->stats['earned']),
                'cost' => Money::toUsd($this->stats['cost']),
                'avg_run_cost' => $this->stats['avg_run_cost'] === null ? null : Money::toUsd($this->stats['avg_run_cost']),
                'max_run_cost' => $this->stats['max_run_cost'] === null ? null : Money::toUsd($this->stats['max_run_cost']),
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
