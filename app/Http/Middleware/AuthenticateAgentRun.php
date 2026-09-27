<?php

namespace App\Http\Middleware;

use App\Models\AgentRun;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an agent service with the token it received for a run (`Authorization: Bearer agr_…`).
 * The token works only while the run is in progress and before its deadline.
 */
class AuthenticateAgentRun
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $run = str_starts_with($token, 'agr_') ? AgentRun::findByToken($token) : null;

        if (! $run) {
            return response()->json(['error' => ['message' => 'Invalid or expired run token.', 'type' => 'authentication_error']], 401);
        }

        $request->attributes->set('agent_run', $run->load(['agent', 'instance', 'organization']));

        return $next($request);
    }
}
