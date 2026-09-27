<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentDestinationController;
use App\Http\Controllers\Api\AgentInstanceController;
use App\Http\Controllers\Api\AppActivityController;
use App\Http\Controllers\Api\AppApiKeyController;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PlaygroundController;
use Illuminate\Support\Facades\Route;

/*
| Customer API for the Quasar web app (ai-accounting-web-app), authenticated with Sanctum tokens.
| Everything is scoped to the user's current organization and limited by their role in it.
| Administration happens in the Filament panel (/admin); the AI gateway lives in routes/gateway.php (/v1).
*/

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum', 'active', 'organization'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard', DashboardController::class);
        Route::get('catalog/models', [CatalogController::class, 'models']);
        Route::get('catalog/packages', [CatalogController::class, 'packages']);

        Route::apiResource('apps', AppController::class);
        Route::get('keys', [AppApiKeyController::class, 'all']);
        Route::get('apps/{app}/keys', [AppApiKeyController::class, 'index']);
        Route::post('apps/{app}/keys', [AppApiKeyController::class, 'store']);
        Route::patch('apps/{app}/keys/{key}', [AppApiKeyController::class, 'update']);
        Route::delete('apps/{app}/keys/{key}', [AppApiKeyController::class, 'destroy']);
        Route::get('apps/{app}/transactions', [AppActivityController::class, 'transactions']);
        Route::get('usage', [AppActivityController::class, 'usage']);
        Route::post('apps/{app}/chat/completions', PlaygroundController::class)->middleware('throttle:60,1');

        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);
        Route::post('organizations/{organization}/switch', [OrganizationController::class, 'switch']);
        Route::patch('organization', [OrganizationController::class, 'update']);
        Route::get('organization/members', [MemberController::class, 'index']);
        Route::patch('organization/members/{member}', [MemberController::class, 'update']);
        Route::delete('organization/members/{member}', [MemberController::class, 'destroy']);
        Route::get('organization/invitations', [InvitationController::class, 'index']);
        Route::post('organization/invitations', [InvitationController::class, 'store']);
        Route::delete('organization/invitations/{invitation}', [InvitationController::class, 'destroy']);
        Route::get('invitations/{token}', [InvitationController::class, 'show']);
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);

        Route::get('agents', [AgentController::class, 'index']);
        Route::post('agents/{agent}/purchase', [AgentController::class, 'purchase']);
        Route::apiResource('agent-instances', AgentInstanceController::class)->parameters(['agent-instances' => 'agentInstance']);
        Route::post('agent-instances/{agentInstance}/run', [AgentInstanceController::class, 'run'])->middleware('throttle:20,1');
        Route::get('agent-instances/{agentInstance}/runs', [AgentInstanceController::class, 'runs']);
        Route::get('agent-runs/{agentRun}', [AgentInstanceController::class, 'showRun']);
        Route::get('agent-instances/{agentInstance}/destinations', [AgentDestinationController::class, 'index']);
        Route::post('agent-instances/{agentInstance}/destinations', [AgentDestinationController::class, 'store']);
        Route::patch('agent-destinations/{agentDestination}', [AgentDestinationController::class, 'update']);
        Route::delete('agent-destinations/{agentDestination}', [AgentDestinationController::class, 'destroy']);
        Route::post('agent-destinations/{agentDestination}/test', [AgentDestinationController::class, 'test'])->middleware('throttle:10,1');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'markAllRead']);

    });
});
