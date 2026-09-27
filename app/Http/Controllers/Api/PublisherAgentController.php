<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentRunResource;
use App\Http\Resources\PublisherAgentResource;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentException;
use App\Services\Agents\AgentManifest;
use App\Services\Agents\ConfigSchema;
use App\Services\Agents\HttpAgent;
use App\Services\Agents\UrlGuard;
use App\Services\Publishers\PublisherEarnings;
use App\Services\Publishers\PublisherService;
use App\Support\AgentStatus;
use App\Support\Money;
use App\Support\OrganizationPermission;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The organization's own marketplace listings: drafts, review, testing against its service
 * and how they sell. Customers' reports stay private; the publisher sees only run outcomes.
 */
class PublisherAgentController extends Controller
{
    use Concerns;

    public function __construct(private PublisherService $publishers, private PublisherEarnings $earnings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeTo(OrganizationPermission::PublishAgents);

        $agents = $this->organization($request)->publishedAgents()->latest('id')->get()
            ->each(fn (Agent $agent) => $agent->setAttribute('stats', $this->earnings->forAgent($agent)));

        return PublisherAgentResource::collection($agents);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::PublishAgents);

        $agent = $this->publishers->create($this->organization($request), $this->validated($request));

        return (new PublisherAgentResource($agent->refresh()))->response()->setStatusCode(201);
    }

    public function show(Request $request, Agent $agent): PublisherAgentResource
    {
        $agent = $this->owned($request, $agent);
        $agent->setAttribute('stats', $this->earnings->forAgent($agent));

        return new PublisherAgentResource($agent);
    }

    public function update(Request $request, Agent $agent): PublisherAgentResource
    {
        $agent = $this->owned($request, $agent);

        return new PublisherAgentResource($this->publishers->update($agent, $this->validated($request, $agent))->refresh());
    }

    public function destroy(Request $request, Agent $agent): JsonResponse
    {
        $agent = $this->owned($request, $agent);
        abort_if($agent->status === AgentStatus::Approved || $agent->runs()->where('trigger', '!=', AgentRun::TRIGGER_TEST)->exists(), 422, 'ایجنت منتشرشده را نمی‌توان حذف کرد؛ برای توقف فروش با پشتیبانی تماس بگیرید.');

        $agent->delete();

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, Agent $agent): PublisherAgentResource
    {
        return new PublisherAgentResource($this->publishers->submit($this->owned($request, $agent))->refresh());
    }

    public function ping(Request $request, Agent $agent, HttpAgent $http): JsonResponse
    {
        $agent = $this->owned($request, $agent);
        $agent->fill(['endpoint_url' => $agent->pending_changes['endpoint_url'] ?? $agent->endpoint_url]);
        $error = $http->ping($agent);

        return response()->json(['ok' => $error === null, 'error' => $error]);
    }

    public function rotateSecret(Request $request, Agent $agent): PublisherAgentResource
    {
        $agent = $this->owned($request, $agent);
        $agent->forceFill(['signing_secret' => Agent::newSigningSecret()])->save();

        return new PublisherAgentResource($agent);
    }

    /**
     * Try the agent with the given parameters; the run happens after the response.
     */
    public function testRun(Request $request, Agent $agent): JsonResponse
    {
        $agent = $this->owned($request, $agent);
        $schema = $this->publishers->testSchema($agent);
        $data = $request->validate($schema->rules(), attributes: $schema->attributes());

        $run = $this->publishers->startTestRun($agent, $schema->normalize($data['config'] ?? []), $request->user());
        RunAgent::dispatchAfterResponse($run);

        return (new AgentRunResource($run))->response()->setStatusCode(202);
    }

    /**
     * Recent runs of the agent. Customers' runs show only their outcome; test runs also their output.
     */
    public function runs(Request $request, Agent $agent): JsonResponse
    {
        $agent = $this->owned($request, $agent);
        $runs = $agent->runs()->latest('id')->limit(50)->get();

        return response()->json(['data' => $runs->map(fn (AgentRun $run) => $this->runSummary($run))]);
    }

    public function showRun(Request $request, Agent $agent, AgentRun $run): JsonResponse
    {
        $agent = $this->owned($request, $agent);
        abort_unless($run->agent_id === $agent->id, 404);

        return response()->json(['data' => [
            ...$this->runSummary($run),
            'report' => $run->isTest() ? $run->report : null,
            'data' => $run->isTest() ? $run->data : null,
        ]]);
    }

    /**
     * Listing fields read from the publisher's manifest, to fill the form with.
     */
    public function importManifest(Request $request, AgentManifest $manifest): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::PublishAgents);
        $url = $request->validate(['url' => ['required', 'url:http,https', 'max:500']])['url'];

        try {
            $fields = $manifest->fetch($url, publicOnly: true);
        } catch (AgentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => collect($fields)->only(Agent::PUBLISHER_FIELDS)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runSummary(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'units' => $run->units,
            'items_found' => $run->items_found,
            'error' => $run->error,
            'notes' => $run->isTest() ? ($run->meta['notes'] ?? []) : [],
            'warnings' => $run->meta['errors'] ?? [],
            'cost' => Money::toUsd($run->publisher_cost),
            'duration_ms' => $run->started_at && $run->finished_at ? (int) $run->started_at->diffInMilliseconds($run->finished_at) : null,
            'created_at' => $run->created_at,
        ];
    }

    private function owned(Request $request, Agent $agent): Agent
    {
        $this->authorizeTo(OrganizationPermission::PublishAgents);
        abort_unless($agent->publisher_organization_id === $this->organization($request)->id, 404);

        return $agent;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Agent $agent = null): array
    {
        $required = $agent ? 'sometimes' : 'required';

        $data = $request->validate([
            'slug' => [$agent ? 'prohibited' : 'required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{2,49}$/', Rule::unique('agents', 'slug')],
            'name' => [$required, 'string', 'max:100'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'icon' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9_]{1,50}$/'],
            'category' => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit_name' => [$required, 'string', 'max:50'],
            'max_units_per_run' => ['sometimes', 'integer', 'between:1,1000'],
            'endpoint_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500', $this->publicUrl()],
            'timeout_seconds' => ['sometimes', 'integer', 'between:5,300'],
            'run_deadline_minutes' => ['sometimes', 'integer', 'between:1,720'],
            'config_schema' => ['sometimes', 'array', 'max:50', $this->validSchema()],
            'packages' => [$agent ? 'sometimes' : 'nullable', 'array', 'max:6'],
            'packages.*.units' => ['required', 'integer', 'between:1,100000', 'distinct'],
            'packages.*.price' => ['required', 'numeric', 'between:0.5,10000'],
        ], attributes: ['packages.*.units' => 'تعداد واحد', 'packages.*.price' => 'قیمت']);

        if (array_key_exists('config_schema', $data)) {
            $data['config_schema'] = ConfigSchema::define($data['config_schema']);
        }

        if (array_key_exists('packages', $data)) {
            $data['packages'] = array_map(fn (array $package) => ['units' => (int) $package['units'], 'price' => number_format((float) $package['price'], 2, '.', '')], $data['packages'] ?? []);
        }

        return $data;
    }

    private function publicUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            try {
                app(UrlGuard::class)->assertPublisherUrl((string) $value);
            } catch (AgentException $e) {
                $fail($e->getMessage());
            }
        };
    }

    private function validSchema(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            try {
                ConfigSchema::define(is_array($value) ? $value : []);
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
    }
}
