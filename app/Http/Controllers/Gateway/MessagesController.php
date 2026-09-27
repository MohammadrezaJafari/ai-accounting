<?php

namespace App\Http\Controllers\Gateway;

use App\Models\Provider;
use App\Services\Gateway\AnthropicStreamCollector;
use App\Services\Gateway\GatewayContext;
use App\Services\Gateway\StreamCollector;
use App\Support\TokenUsage;
use Illuminate\Http\Request;

/**
 * Native Anthropic Messages API (`/v1/messages`) so Claude SDKs and tools work unchanged.
 */
class MessagesController extends ProxyController
{
    protected function endpoint(): string
    {
        return 'messages';
    }

    protected function nativeFormat(): ?string
    {
        return Provider::NATIVE_ANTHROPIC;
    }

    protected function upstream(GatewayContext $ctx, Request $request): array
    {
        $headers = [
            'x-api-key' => $ctx->providerKey->api_key,
            'anthropic-version' => $request->header('anthropic-version', '2023-06-01'),
        ];

        if ($beta = $request->header('anthropic-beta')) {
            $headers['anthropic-beta'] = $beta;
        }

        return [
            $this->upstreamHttp()->withHeaders($headers),
            rtrim($ctx->model->provider->native_base_url, '/').'/v1/messages',
        ];
    }

    protected function usageFromResponse(array $json): TokenUsage
    {
        return TokenUsage::fromAnthropic($json['usage'] ?? null);
    }

    protected function collector(): StreamCollector
    {
        return new AnthropicStreamCollector;
    }
}
