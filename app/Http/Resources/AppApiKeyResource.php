<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->app_id,
            'app' => $this->whenLoaded('app', fn () => ['id' => $this->app->id, 'name' => $this->app->name]),
            'name' => $this->name,
            'key_prefix' => $this->key_prefix,
            'allowed_providers' => $this->allowed_providers,
            'allowed_models' => $this->allowed_models,
            'spend_limit' => Money::toUsd($this->spend_limit),
            'spent' => Money::toUsd($this->spent),
            'expires_at' => $this->expires_at,
            'last_used_at' => $this->last_used_at,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
