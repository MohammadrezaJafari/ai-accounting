<?php

namespace App\Http\Resources;

use App\Support\Money;
use App\Support\Percent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'balance' => Money::toUsd($this->balance),
            'markup_percent' => Percent::fromBps($this->markup_bps),
            'is_active' => $this->is_active,
            'api_keys_count' => $this->whenCounted('apiKeys'),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
