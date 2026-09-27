<?php

namespace App\Http\Controllers\Api;

use App\Models\App;
use Illuminate\Http\Request;

trait Concerns
{
    /**
     * 404 unless the app belongs to the current user (admins see every app).
     */
    protected function ownedApp(Request $request, App $app): App
    {
        $user = $request->user();
        abort_unless($app->user_id === $user->id || $user->isAdmin(), 404);

        return $app;
    }

    protected function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 25), 1), 200);
    }
}
