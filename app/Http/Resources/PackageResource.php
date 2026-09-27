<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => Money::toUsd($this->price),
            'credit' => Money::toUsd($this->credit),
            'bonus' => Money::toUsd(max(0, $this->credit - $this->price)),
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
