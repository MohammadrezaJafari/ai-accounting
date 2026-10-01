<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Rahap Hub's service API: `Authorization: Bearer <SERVICE_KEY>`. Without a configured key
 * the endpoints do not exist (404); a wrong key is 401. Every answer carries `X-Request-Id`.
 */
class AuthenticateServiceKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) $request->header('X-Request-Id');
        $requestId = preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId) ? $requestId : (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        $key = (string) config('oidc.service_key');

        $response = match (true) {
            $key === '' => self::error($request, 'not_found', 'Not found.', 404),
            ! hash_equals($key, (string) $request->bearerToken()) => self::error($request, 'unauthenticated', 'Invalid service key.', 401),
            default => $next($request),
        };

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     */
    public static function error(Request $request, string $code, string $message, int $status, ?array $errors = null): JsonResponse
    {
        return response()->json(array_filter([
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
            'request_id' => $request->attributes->get('request_id'),
        ], fn ($value) => $value !== null), $status);
    }
}
