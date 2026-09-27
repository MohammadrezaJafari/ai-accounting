<?php

namespace App\Http\Controllers\AgentApi;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Services\Agents\AgentException;
use App\Services\Agents\AgentResult;
use App\Services\Agents\AgentRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where an agent that accepted a run (202) posts its result, with the run token.
 */
class AgentCallbackController extends Controller
{
    public function __invoke(Request $request, int $run, AgentRunner $runner): JsonResponse
    {
        /** @var AgentRun $agentRun */
        $agentRun = $request->attributes->get('agent_run');
        abort_unless($agentRun->id === $run, 403, 'This token belongs to another run.');

        try {
            $result = AgentResult::fromPayload($request->json()->all(), $agentRun->instance->state ?? []);
        } catch (AgentException $e) {
            if ($request->input('status') !== 'failed') {
                return response()->json(['error' => ['message' => $e->getMessage(), 'type' => 'invalid_request_error']], 422);
            }

            $agentRun = $runner->fail($agentRun, $e->getMessage());

            return response()->json(['ok' => true, 'status' => $agentRun->status, 'units' => $agentRun->units]);
        }

        $agentRun = $runner->complete($agentRun, $result);

        return response()->json(['ok' => true, 'status' => $agentRun->status, 'units' => $agentRun->units]);
    }
}
