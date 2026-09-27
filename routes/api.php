<?php

use App\Http\Controllers\Api\AppActivityController;
use App\Http\Controllers\Api\AppApiKeyController;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

/*
| Customer API for the Quasar web app (ai-accounting-web-app), authenticated with Sanctum tokens.
| Administration happens in the Filament panel (/admin); the AI gateway lives in routes/gateway.php (/v1).
*/

Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('dashboard', DashboardController::class);
    Route::get('catalog/models', [CatalogController::class, 'models']);
    Route::get('catalog/packages', [CatalogController::class, 'packages']);

    Route::apiResource('apps', AppController::class);
    Route::get('apps/{app}/keys', [AppApiKeyController::class, 'index']);
    Route::post('apps/{app}/keys', [AppApiKeyController::class, 'store']);
    Route::patch('apps/{app}/keys/{key}', [AppApiKeyController::class, 'update']);
    Route::delete('apps/{app}/keys/{key}', [AppApiKeyController::class, 'destroy']);
    Route::get('apps/{app}/transactions', [AppActivityController::class, 'transactions']);
    Route::get('usage', [AppActivityController::class, 'usage']);

    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

});
