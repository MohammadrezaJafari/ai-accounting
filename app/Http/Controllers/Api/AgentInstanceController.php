<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentInstanceResource;
use App\Http\Resources\AgentRunResource;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\ConfigSchema;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The organization's configured agents, their runs and reports. Parameters are checked
 * against the agent's config schema.
 * Every member can read reports; configuring and running needs the apps permission.
 */
class AgentInstanceController extends Controller
{
    use Concerns;

    public function index(Request $request): AnonymousResourceCollection
    {
        return AgentInstanceResource::collection(
            $this->organization($request)->agentInstances()->with(['agent', 'app', 'latestRun', 'destinations'])->latest('id')->get()
        );
    }

    public function store(Request $request): AgentInstanceResource
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $agent = Agent::query()->active()->findOrFail($request->integer('agent_id'));

        $instance = $this->organization($request)->agentInstances()->create(
            $this->validated($request, $agent) + ['agent_id' => $agent->id, 'created_by' => $request->user()->id]
        );

        return new AgentInstanceResource($instance->load(['agent', 'app', 'latestRun', 'destinations']));
    }

    public function show(Request $request, AgentInstance $agentInstance): AgentInstanceResource
    {
        return new AgentInstanceResource($this->owned($request, $agentInstance)->load(['agent', 'app', 'latestRun', 'destinations']));
    }

    public function update(Request $request, AgentInstance $agentInstance): AgentInstanceResource
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $instance = $this->owned($request, $agentInstance);

        $instance->update($this->validated($request, $instance->agent, $instance));

        return new AgentInstanceResource($instance->load(['agent', 'app', 'latestRun', 'destinations']));
    }

    public function destroy(Request $request, AgentInstance $agentInstance): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $this->owned($request, $agentInstance)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Run now; the work happens after the response is sent.
     */
    public function run(Request $request, AgentInstance $agentInstance, AgentRunner $runner): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $instance = $this->owned($request, $agentInstance);

        if ($instance->hasRunInProgress()) {
            throw ValidationException::withMessages(['run' => 'یک اجرا همین حالا در جریان است.']);
        }

        $run = $runner->start($instance, AgentRun::TRIGGER_MANUAL);
        RunAgent::dispatchAfterResponse($run);

        return (new AgentRunResource($run))->response()->setStatusCode(202);
    }

    public function runs(Request $request, AgentInstance $agentInstance): AnonymousResourceCollection
    {
        return AgentRunResource::collection(
            $this->owned($request, $agentInstance)->runs()->latest('id')->paginate($this->perPage($request))
        );
    }

    public function showRun(Request $request, AgentRun $agentRun): AgentRunResource
    {
        abort_unless($agentRun->organization_id === $this->organization($request)->id, 404);

        return (new AgentRunResource($agentRun))->withReport();
    }

    private function owned(Request $request, AgentInstance $instance): AgentInstance
    {
        abort_unless($instance->organization_id === $this->organization($request)->id, 404);

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Agent $agent, ?AgentInstance $instance = null): array
    {
        $required = $instance ? 'sometimes' : 'required';
        $schema = ConfigSchema::for($agent);
        $previous = $instance->config ?? [];

        $data = $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'app_id' => [$required, 'integer', Rule::exists('apps', 'id')->where('organization_id', $this->organization($request)->id)],
            'run_hours' => ['sometimes', 'array', 'max:6'],
            'run_hours.*' => ['integer', 'distinct', 'between:0,23'],
            'run_days' => ['sometimes', 'array', 'max:7'],
            'run_days.*' => ['integer', 'distinct', 'between:0,6'],
            'notify_empty' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            ...(! $instance || $request->has('config') ? $schema->rules($previous) : []),
        ], attributes: $schema->attributes());

        if (! $instance || $request->has('config')) {
            $data['config'] = $schema->normalize($data['config'] ?? [], $previous);
        }

        foreach (['run_hours', 'run_days'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = array_values(array_map('intval', $data[$field]));
            }
        }

        return $data;
    }
}
