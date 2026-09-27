<?php

use App\Http\Middleware\AuthenticateAgentRun;
use App\Http\Middleware\AuthenticateAppKey;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::prefix('v1')->group(base_path('routes/gateway.php'));
            Route::prefix('agent-api')->group(base_path('routes/agent.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'active' => EnsureActiveUser::class,
            'app.key' => AuthenticateAppKey::class,
            'agent.run' => AuthenticateAgentRun::class,
            'organization' => ResolveOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'v1/*', 'agent-api/*') || $request->expectsJson(),
        );
    })->create();
