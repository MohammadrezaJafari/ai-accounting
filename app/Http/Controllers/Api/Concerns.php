<?php

namespace App\Http\Controllers\Api;

use App\Models\App;
use App\Models\Organization;
use App\Support\OrganizationPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

trait Concerns
{
    /**
     * The organization the request works in (resolved by the `organization` middleware).
     */
    protected function organization(Request $request): Organization
    {
        return $request->user()->currentOrganization;
    }

    /**
     * 404 unless the app belongs to the current organization (admins see every app).
     */
    protected function ownedApp(Request $request, App $app): App
    {
        $user = $request->user();
        abort_unless($app->organization_id === $user->current_organization_id || $user->isAdmin(), 404);

        return $app;
    }

    protected function authorizeTo(OrganizationPermission $permission): void
    {
        abort_unless(Gate::allows($permission->value), 403, 'نقش شما در این سازمان اجازهٔ این کار را نمی‌دهد.');
    }

    protected function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 25), 1), 200);
    }
}
