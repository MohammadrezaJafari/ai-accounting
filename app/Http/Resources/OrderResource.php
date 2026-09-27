<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => Money::toUsd($this->amount),
            'credit' => Money::toUsd($this->credit),
            'status' => $this->status,
            'gateway' => $this->gateway,
            'gateway_ref' => $this->gateway_ref,
            'meta' => $this->meta,
            'app' => $this->whenLoaded('app', fn () => ['id' => $this->app->id, 'name' => $this->app->name]),
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email]),
            'package' => $this->whenLoaded('package', fn () => $this->package ? ['id' => $this->package->id, 'name' => $this->package->name] : null),
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
