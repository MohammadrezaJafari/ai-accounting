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
            'amount' => Money::toUsd($this->amount),
            'reference' => $this->reference,
            'note' => $this->note,
            'paid_at' => $this->paid_at,
        ];
    }
}
