<?php

namespace App\Http\Controllers\AgentApi;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Services\Agents\AgentException;
use App\Services\Agents\AgentLlm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OpenAI-compatible chat completions for agent services, billed to the run (not streamed).
 * Point an OpenAI SDK at `platform.llm_base_url` with the run token as the API key.
 */
class AgentChatController extends Controller
{
    public function __invoke(Request $request, AgentLlm $llm): JsonResponse
    {
        /** @var AgentRun $run */
        $run = $request->attributes->get('agent_run');

        $request->validate([
            'model' => ['nullable', 'string', 'max:100'],
            'messages' => ['required', 'array', 'min:1'],
        ]);

        if ($request->boolean('stream')) {
            return response()->json(['error' => ['message' => 'Streaming is not supported for agents.', 'type' => 'invalid_request_error']], 422);
        }

        try {
            ['status' => $status, 'body' => $body] = $llm->chat($run, $request->json()->all());
        } catch (AgentException $e) {
            return response()->json(['error' => ['message' => $e->getMessage(), 'type' => 'permission_error']], 403);
        }

        return response()->json($body, $status);
    }
}
