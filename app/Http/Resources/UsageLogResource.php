<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsageLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'app' => $this->whenLoaded('app', fn () => ['id' => $this->app->id, 'name' => $this->app->name]),
            'api_key' => $this->whenLoaded('apiKey', fn () => $this->apiKey ? ['id' => $this->apiKey->id, 'name' => $this->apiKey->name] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider?->slug),
            'endpoint' => $this->endpoint,
            'model' => $this->model,
            'stream' => $this->stream,
            'input_tokens' => $this->input_tokens,
            'cached_input_tokens' => $this->cached_input_tokens,
            'cache_write_tokens' => $this->cache_write_tokens,
            'output_tokens' => $this->output_tokens,
            'charge' => Money::toUsd($this->charge),
            'status_code' => $this->status_code,
            'latency_ms' => $this->latency_ms,
            'error' => $this->error,
            'created_at' => $this->created_at,
        ];

    }
}
