<?php

namespace App\Services\Gateway;

use App\Models\AiModel;
use App\Models\App;
use App\Models\AppApiKey;
use App\Models\ProviderKey;
use Illuminate\Support\Str;

/**
 * Everything known about one in-flight gateway request.
 */
final class GatewayContext
{
    public readonly string $requestId;

    public readonly float $startedAt;

    public function __construct(
        public readonly AppApiKey $key,
        public readonly App $app,
        public readonly AiModel $model,
        public readonly ProviderKey $providerKey,
        public readonly string $endpoint,
        public readonly bool $stream,
        public readonly ?string $ip,
    ) {
        $this->requestId = (string) Str::uuid();
        $this->startedAt = microtime(true);
    }

    public function latencyMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
