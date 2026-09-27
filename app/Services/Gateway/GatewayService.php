<?php

namespace App\Services\Gateway;

use App\Models\AiModel;
use App\Models\AppApiKey;
use App\Models\UsageLog;
use App\Services\PricingService;
use App\Services\WalletService;
use App\Support\TokenUsage;
use Illuminate\Http\Request;

class GatewayService
{
    public function __construct(
        private PricingService $pricing,
        private WalletService $wallet,
        private UpstreamKeyPicker $keys,
    ) {}

    public function context(Request $request, string $publicModelId, string $endpoint, bool $stream, ?string $nativeFormat = null): GatewayContext
    {
        /** @var AppApiKey $key */
        $key = $request->attributes->get('app_key');

        $model = AiModel::query()->available()->with('provider')->where('public_id', $publicModelId)->first();

        if (! $model) {
            throw new GatewayException(404, "The model `{$publicModelId}` does not exist or is not available.", 'not_found_error');
        }

        if (! $key->allowsModel($model)) {
            throw new GatewayException(403, "This API key is not allowed to use `{$publicModelId}`.", 'permission_error');
        }

        if ($nativeFormat !== null && $model->provider->native_format !== $nativeFormat) {
            throw new GatewayException(400, "The model `{$publicModelId}` is not available on this endpoint.");
        }

        return new GatewayContext(
            key: $key,
            app: $key->app,
            model: $model,
            providerKey: $this->keys->pick($model->provider),
            endpoint: $endpoint,
            stream: $stream,
            ip: $request->ip(),
        );
    }

    /**
     * Price the request, debit the app and write the usage log.
     */
    public function record(GatewayContext $ctx, TokenUsage $usage, int $status, ?string $error = null): UsageLog
    {
        $this->keys->reportResult($ctx->providerKey, $status);

        $amounts = $usage->isEmpty() ? ['cost' => 0, 'charge' => 0] : $this->pricing->calculate($ctx->model, $ctx->app, $usage);

        $this->wallet->chargeUsage($ctx->app, $ctx->key, $amounts['charge']);

        AppApiKey::query()->whereKey($ctx->key->id)->update(['last_used_at' => now()]);

        return UsageLog::query()->create([
            'request_id' => $ctx->requestId,
            'app_id' => $ctx->app->id,
            'app_api_key_id' => $ctx->key->id,
            'ai_model_id' => $ctx->model->id,
            'provider_id' => $ctx->model->provider_id,
            'endpoint' => $ctx->endpoint,
            'model' => $ctx->model->public_id,
            'stream' => $ctx->stream,
            'input_tokens' => $usage->input,
            'cached_input_tokens' => $usage->cachedInput,
            'cache_write_tokens' => $usage->cacheWrite,
            'output_tokens' => $usage->output,
            'cost' => $amounts['cost'],
            'charge' => $amounts['charge'],
            'status_code' => $status,
            'latency_ms' => $ctx->latencyMs(),
            'error' => $error ? mb_substr($error, 0, 500) : null,
            'ip' => $ctx->ip,
        ]);
    }

    /**
     * Rough token estimate (~4 characters per token) for streams that ended without a usage report,
     * e.g. when the client disconnected. Keeps us from serving tokens for free.
     */
    public function estimate(string $requestBody, string $outputText): TokenUsage
    {
        return new TokenUsage(
            input: (int) ceil(mb_strlen($requestBody) / 4),
            output: (int) ceil(mb_strlen($outputText) / 4),
        );
    }
}
