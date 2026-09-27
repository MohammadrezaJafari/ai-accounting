<?php

namespace App\Http\Middleware;

use App\Services\OrganizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads the signed-in user's current organization; customer API routes are scoped to it.
 */
class ResolveOrganization
{
    public function __construct(private OrganizationService $organizations) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $this->organizations->current($user);
        }

        return $next($request);
    }
}
