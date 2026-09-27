<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use App\Services\Gateway\GatewayError;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
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
            return GatewayError::response($request, 402, 'This API key has reached its spend limit.', 'billing_error');
        }

        if ($key->app->balance <= $this->settings->get('min_balance')) {
            return GatewayError::response($request, 402, 'Insufficient balance. Please top up your app.', 'billing_error');
        }

        $request->attributes->set('app_key', $key);

        return $next($request);
    }
}
