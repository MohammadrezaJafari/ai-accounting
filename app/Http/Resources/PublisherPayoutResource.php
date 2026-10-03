<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublisherPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'app' => $this->whenLoaded('app', fn () => $this->app ? ['id' => $this->app->id, 'name' => $this->app->name] : null),
            'amount' => Money::toUsd($this->amount),
            'reference' => $this->reference,
            'note' => $this->note,
            'paid_at' => $this->paid_at,
        ];
    }
}
