<?php

use App\Http\Controllers\Gateway\ChatCompletionsController;
use App\Http\Controllers\Gateway\MessagesController;
use App\Http\Controllers\Gateway\ModelsController;
use Illuminate\Support\Facades\Route;

/*
| AI gateway, mounted at /v1. Apps point their OpenAI / Anthropic SDK base URL here
| and authenticate with an app API key.
*/

Route::middleware('app.key')->group(function () {
    Route::get('models', ModelsController::class);
    Route::post('chat/completions', ChatCompletionsController::class);
    Route::post('messages', MessagesController::class);
});
