<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use App\Services\Gateway\GatewayError;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates gateway calls with an app API key sent as `Authorization: Bearer`
 * (OpenAI SDKs) or `x-api-key` (Anthropic SDKs).
 */
class AuthenticateAppKey
{
    public function __construct(private ApiKeyService $keys, private SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken() ?: $request->header('x-api-key');

        if (! $plain) {
            return GatewayError::response($request, 401, 'Missing API key.', 'authentication_error');
        }

        $key = $this->keys->findActive($plain);

        if (! $key || ! $key->is_active || $key->isExpired() || ! $key->app->is_active) {
            return GatewayError::response($request, 401, 'Invalid, revoked or expired API key.', 'authentication_error');
        }

        if ($key->isOverSpendLimit()) {
            return GatewayError::response($request, 402, "This API key has reached its {$key->spend_limit_period->value} spend limit.", 'billing_error');
        }

        if ($key->app->isOverSpendLimit()) {
            return GatewayError::response($request, 402, "This app has reached its {$key->app->spend_limit_period->value} spend limit.", 'billing_error');
        }

        if ($key->app->balance <= $this->settings->get('min_balance')) {
            return GatewayError::response($request, 402, 'Insufficient balance. Please top up your app.', 'billing_error');
        }

        $limit = $key->rateLimitPerMinute();
        $limiterKey = "gateway-key:{$key->id}";

        if ($limit > 0 && ! RateLimiter::attempt($limiterKey, $limit, fn () => true, 60)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);
            $response = GatewayError::response($request, 429, "Rate limit reached: this API key allows {$limit} requests per minute. Retry in {$retryAfter}s.", 'rate_limit_error');
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $this->withRateLimitHeaders($response, $limit, 0);
        }

        $request->attributes->set('app_key', $key);

        $response = $next($request);

        return $limit > 0 ? $this->withRateLimitHeaders($response, $limit, RateLimiter::remaining($limiterKey, $limit)) : $response;
    }

    private function withRateLimitHeaders(Response $response, int $limit, int $remaining): Response
    {
        $response->headers->set('x-ratelimit-limit-requests', (string) $limit);
        $response->headers->set('x-ratelimit-remaining-requests', (string) $remaining);

        return $response;
    }
}
