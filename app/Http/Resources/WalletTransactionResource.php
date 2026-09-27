<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->app_id,
            'type' => $this->type,
            'amount' => Money::toUsd($this->amount),
            'balance_after' => Money::toUsd($this->balance_after),
            'description' => $this->description,
            'order_id' => $this->order_id,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,
        ];
    }
}
