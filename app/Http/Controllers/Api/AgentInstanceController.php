<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentInstanceResource;
use App\Http\Resources\AgentRunResource;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentRunner;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The organization's configured agents (e.g. news monitors), their runs and reports.
 * Every member can read reports; configuring and running needs the apps permission.
 */
class AgentInstanceController extends Controller
{
    use Concerns;

    public function __construct(private AgentRegistry $registry) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AgentInstanceResource::collection(
            $this->organization($request)->agentInstances()->with(['agent', 'app', 'latestRun'])->latest('id')->get()
        );
    }

    public function store(Request $request): AgentInstanceResource
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $agent = Agent::query()->active()->findOrFail($request->integer('agent_id'));

        $instance = $this->organization($request)->agentInstances()->create(
            $this->validated($request, $agent, true) + ['agent_id' => $agent->id, 'created_by' => $request->user()->id]
        );

        return new AgentInstanceResource($instance->load(['agent', 'app', 'latestRun']));
    }

    public function show(Request $request, AgentInstance $agentInstance): AgentInstanceResource
    {
        return new AgentInstanceResource($this->owned($request, $agentInstance)->load(['agent', 'app', 'latestRun']));
    }

    public function update(Request $request, AgentInstance $agentInstance): AgentInstanceResource
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $instance = $this->owned($request, $agentInstance);

        $instance->update($this->validated($request, $instance->agent, false));

        return new AgentInstanceResource($instance->load(['agent', 'app', 'latestRun']));
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
    private function validated(Request $request, Agent $agent, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $handler = $this->registry->handler($agent);
        $configRules = collect($handler->rules())->mapWithKeys(fn ($rules, $key) => ["config.{$key}" => $rules])->all();

        $data = $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'app_id' => [$required, 'integer', Rule::exists('apps', 'id')->where('organization_id', $this->organization($request)->id)],
            'run_hours' => ['sometimes', 'array', 'max:6'],
            'run_hours.*' => ['integer', 'distinct', 'between:0,23'],
            'is_active' => ['sometimes', 'boolean'],
            'config' => [$required, 'array'],
            ...($creating || $request->has('config') ? $configRules : []),
        ]);

        if (array_key_exists('config', $data)) {
            $data['config'] = $handler->normalize($data['config']);
        }

        if (array_key_exists('run_hours', $data)) {
            $data['run_hours'] = array_values(array_map('intval', $data['run_hours']));
        }

        return $data;
    }
}
