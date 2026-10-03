<?php

namespace App\Http\Resources;

use App\Models\AgentPackage;
use App\Services\Agents\ConfigSchema;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A marketplace listing with its packages, the parameters customers fill in and the current
 * organization's remaining units (`credits`, set by the controller). How the agent is
 * hosted and what it costs us stay private.
 */
class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'icon' => $this->icon ?: 'smart_toy',
            'category' => $this->category,
            'kind' => $this->kind?->value ?? 'report',
            'publisher' => $this->publisherDisplayName() ? ['name' => $this->publisherDisplayName(), 'url' => $this->publisher_organization_id ? $this->publisher?->publisher_url : $this->publisher_url] : null,
            'unit_name' => $this->unit_name,
            'max_units_per_run' => $this->max_units_per_run,
            'config_schema' => ConfigSchema::for($this->resource)->fields(),
            'run_input_label' => $this->run_input_label,
            'credits' => (int) ($this->credits ?? 0),
            'free_trial_units' => $this->free_trial_units,
            'trial_available' => (bool) ($this->trial_available ?? false),
            'packages' => $this->whenLoaded('packages', fn () => $this->packages->map(fn (AgentPackage $package) => [
                'id' => $package->id,
                'name' => $package->name,
                'units' => $package->units,
                'price' => Money::toUsd($package->price),
                'unit_price' => Money::toUsd(intdiv($package->price, max(1, $package->units))),
            ])),
        ];
    }
}
