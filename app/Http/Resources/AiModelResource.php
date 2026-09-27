<?php

namespace App\Http\Resources;

use App\Models\AiModel;
use App\Services\PricingService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing model entry. `price` is the effective sell price in USD per 1M tokens,
 * for the app in the `pricing_app` request attribute when set. Cost is never exposed.
 */
class AiModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AiModel $model */
        $model = $this->resource;
        $pricing = app(PricingService::class);
        $forApp = $request->attributes->get('pricing_app');

        return [
            'id' => $model->id,
            'name' => $model->name,
            'description' => $model->description,
            'public_id' => $model->public_id,
            'provider' => $model->relationLoaded('provider') ? [
                'id' => $model->provider->id,
                'slug' => $model->provider->slug,
                'name' => $model->provider->name,
                'native_format' => $model->provider->native_format,
            ] : null,
            'context_window' => $model->context_window,
            'is_active' => $model->is_active,
            'is_featured' => $model->is_featured,
            'price' => array_map(Money::toUsd(...), $pricing->sellPrices($model, $forApp)),
        ];

    }
}
