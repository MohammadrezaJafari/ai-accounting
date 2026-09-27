<?php

namespace App\Http\Resources;

use App\Models\AgentPackage;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agent product with its packages and the current organization's remaining units
 * (`credits`, set by the controller).
 */
class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'unit_name' => $this->unit_name,
            'credits' => (int) ($this->credits ?? 0),
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
