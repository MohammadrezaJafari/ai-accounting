<?php

namespace App\Services\Gateway;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Errors in the format the client SDK expects: Anthropic for /v1/messages, OpenAI otherwise.
 */
final class GatewayError
{
    public static function response(Request $request, int $status, string $message, string $type = 'invalid_request_error'): JsonResponse
    {
        if ($request->is('v1/messages*')) {
            return response()->json(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]], $status);
        }

        return response()->json(['error' => ['message' => $message, 'type' => $type, 'code' => $status]], $status);
    }
}
