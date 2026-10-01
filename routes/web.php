<?php

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Sign-in with the organization's identity provider (OIDC_ISSUER); 404 while it is not configured.
Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->middleware('throttle:30,1');
Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->middleware('throttle:30,1');
Route::get('auth/oidc/logout', [OidcController::class, 'logout']);
Route::post('auth/backchannel-logout', [OidcController::class, 'backchannelLogout'])->withoutMiddleware(ValidateCsrfToken::class);
