<?php

namespace App\Http\Controllers\Gateway;

use App\Services\Gateway\GatewayContext;
use App\Services\Gateway\OpenAiStreamCollector;
use App\Services\Gateway\StreamCollector;
use App\Support\TokenUsage;
use Illuminate\Http\Request;
use stdClass;

/**
 * OpenAI-compatible chat completions for every provider (OpenAI, Anthropic and Gemini
 * all expose an OpenAI-compatible endpoint, configured as the provider base_url).
 */
class ChatCompletionsController extends ProxyController
{
    protected function endpoint(): string
    {
        return 'chat.completions';
    }

    protected function nativeFormat(): ?string
    {
        return null;
    }

    protected function upstream(GatewayContext $ctx, Request $request): array
    {
        return [
            $this->upstreamHttp()->withToken($ctx->providerKey->api_key),
            rtrim($ctx->model->provider->base_url, '/').'/chat/completions',
        ];
    }

    protected function prepareBody(stdClass $body, bool $stream): stdClass
    {
        if ($stream) {
            // Ask the provider to send a final usage chunk so the stream can be billed.
            $body->stream_options = (object) array_merge((array) ($body->stream_options ?? []), ['include_usage' => true]);
        }

        return $body;
    }

    protected function usageFromResponse(array $json): TokenUsage
    {
        return TokenUsage::fromOpenAi($json['usage'] ?? null);
    }

    protected function collector(): StreamCollector
    {
        return new OpenAiStreamCollector;
    }
}
