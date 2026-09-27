<?php

use App\Http\Controllers\AgentApi\AgentCallbackController;
use App\Http\Controllers\AgentApi\AgentChatController;
use Illuminate\Support\Facades\Route;

/*
| API for agent services (marketplace agents), mounted at /agent-api and authenticated with
| the token each run request carries. See App\Services\Agents\HttpAgent for the protocol.
*/

Route::middleware(['agent.run', 'throttle:120,1'])->group(function () {
    Route::post('runs/{run}/result', AgentCallbackController::class)->whereNumber('run');
    Route::post('v1/chat/completions', AgentChatController::class);
});
