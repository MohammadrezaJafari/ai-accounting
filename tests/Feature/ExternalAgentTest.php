<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentCredit;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Models\AiModel;
use App\Models\App;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\UsageLog;
use App\Models\User;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\HttpAgent;
use App\Support\AgentDriver;
use App\Support\Money;
use App\Support\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExternalAgentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private App $wallet;

    private Agent $agent;

    /** What the agent service answers next. */
    private mixed $reply = null;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake([
            'agent.test/*' => fn () => $this->reply,
            'api.openai.test/*' => Http::response([
                'model' => 'gpt-4o-mini-upstream',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'تحلیل']]],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500],
            ]),
        ]);

        $provider = Provider::query()->create(['slug' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.test/v1']);
        $provider->keys()->create(['name' => 'main', 'api_key' => 'sk-real']);

        foreach (['gpt-4o-mini', 'gpt-4o'] as $model) {
            AiModel::query()->create([
                'provider_id' => $provider->id, 'name' => $model, 'public_id' => $model, 'upstream_id' => "{$model}-upstream",
                'input_price' => Money::fromUsd('1'), 'output_price' => Money::fromUsd('2'),
            ]);
        }

        $this->agent = Agent::query()->create([
            'slug' => 'competitor-watch',
            'driver' => AgentDriver::Http,
            'endpoint_url' => 'https://agent.test/run',
            'name' => 'پایش رقبا',
            'unit_name' => 'گزارش',
            'max_units_per_run' => 5,
            'model' => 'gpt-4o-mini',
            'allowed_models' => ['gpt-4o-mini'],
            'max_cost_per_run' => Money::fromUsd('1'),
            'config_schema' => [
                ['key' => 'domain', 'label' => 'دامنه', 'type' => 'url', 'required' => true],
                ['key' => 'competitors', 'label' => 'رقبا', 'type' => 'tags', 'max_items' => 5],
                ['key' => 'depth', 'label' => 'عمق', 'type' => 'select', 'default' => 'quick', 'options' => [['value' => 'quick', 'label' => 'سریع'], ['value' => 'deep', 'label' => 'کامل']]],
                ['key' => 'api_key', 'label' => 'کلید CRM', 'type' => 'secret', 'required' => true],
            ],
        ]);

        $this->organization = Organization::factory()->create();
        User::factory()->inOrganization($this->organization)->create();
        $this->wallet = $this->organization->apps()->create(['name' => 'Wallet']);
        AgentCredit::query()->create([
            'organization_id' => $this->organization->id, 'agent_id' => $this->agent->id,
            'units' => 10, 'value' => Money::fromUsd('10'),
        ]);
    }

    private function competitorWatch(): AgentInstance
    {
        return $this->organization->agentInstances()->create([
            'agent_id' => $this->agent->id,
            'app_id' => $this->wallet->id,
            'name' => 'رقبای فروشگاه',
            'config' => ['domain' => 'https://shop.test', 'competitors' => ['a.test'], 'depth' => 'quick', 'api_key' => 'crm-secret'],
            'state' => ['cursor' => 1],
        ]);
    }

    private function runAgent(AgentInstance $instance): AgentRun
    {
        $runner = app(AgentRunner::class);

        return $runner->execute($runner->start($instance, AgentRun::TRIGGER_MANUAL));
    }

    private function units(): int
    {
        return AgentCredit::query()->sole()->units;
    }

    /**
     * The request the platform sent to the agent service.
     *
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        $request = Http::recorded(fn (ClientRequest $request) => $request->url() === 'https://agent.test/run')->last()[0];

        return json_decode($request->body(), true);
    }

    public function test_parameters_follow_the_agent_schema_and_secrets_are_never_returned(): void
    {
        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Developer)->create());
        $base = ['agent_id' => $this->agent->id, 'app_id' => $this->wallet->id, 'name' => 'رقبا'];

        $this->postJson('/api/v1/agent-instances', $base + ['config' => ['depth' => 'forever']])
            ->assertJsonValidationErrors(['config.domain', 'config.api_key', 'config.depth']);

        $response = $this->postJson('/api/v1/agent-instances', $base + ['config' => [
            'domain' => 'https://shop.test', 'competitors' => ['a.test', 'a.test', ' b.test '], 'api_key' => 'crm-secret', 'extra' => 'dropped',
        ]])->assertCreated()
            ->assertJsonPath('data.config.depth', 'quick')
            ->assertJsonPath('data.config.competitors', ['a.test', 'b.test'])
            ->assertJsonPath('data.config.api_key', null)
            ->assertJsonPath('data.secrets_set', ['api_key'])
            ->assertJsonMissingPath('data.config.extra');

        $instance = AgentInstance::query()->findOrFail($response->json('data.id'));
        $this->assertSame('crm-secret', $instance->config['api_key']);

        // A blank secret keeps the saved one.
        $this->patchJson("/api/v1/agent-instances/{$instance->id}", ['config' => ['domain' => 'https://shop2.test', 'api_key' => '']])->assertOk();
        $this->assertSame(['https://shop2.test', 'crm-secret'], [$instance->refresh()->config['domain'], $instance->config['api_key']]);

        $this->getJson('/api/v1/agents')->assertOk()
            ->assertJsonPath('data.0.config_schema.2.options.1.value', 'deep')
            ->assertJsonMissingPath('data.0.endpoint_url')
            ->assertJsonMissingPath('data.0.signing_secret');
    }

    public function test_a_synchronous_result_uses_the_units_it_is_worth(): void
    {
        $this->reply = Http::response([
            'status' => 'succeeded',
            'report' => "## رقبا\nقیمت رقیب الف کاهش یافت.",
            'units' => 3,
            'items' => 7,
            'notes' => ['۷ صفحه بررسی شد.'],
            'state' => ['cursor' => 2],
            'data' => ['prices' => [['sku' => 'x', 'price' => 10]]],
        ]);
        $instance = $this->competitorWatch();

        $run = $this->runAgent($instance);

        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame([3, 7, 7], [$run->units, $run->items_found, $this->units()]);
        $this->assertSame(Money::fromUsd('3'), $run->revenue);
        $this->assertSame(['cursor' => 2], $instance->refresh()->state);
        $this->assertSame(['۷ صفحه بررسی شد.'], $run->meta['notes']);
        $this->assertSame(10, $run->data['prices'][0]['price']);
        $this->assertNull($run->token_hash);

        $request = Http::recorded()->first()[0];
        $payload = $this->sentPayload();
        $this->assertSame(
            'sha256='.HttpAgent::signature($request->header('X-Agent-Timestamp')[0], $request->body(), $this->agent->signing_secret),
            $request->header('X-Agent-Signature')[0],
        );
        $this->assertSame('crm-secret', $payload['config']['api_key']);
        $this->assertSame(['cursor' => 1], $payload['state']);
        $this->assertStringStartsWith('agr_', $payload['platform']['token']);
        $this->assertSame(url("/agent-api/runs/{$run->id}/result"), $payload['platform']['result_url']);

        // More units than a run may use are capped.
        $this->reply = Http::response(['status' => 'succeeded', 'report' => 'x', 'units' => 50]);
        $this->assertSame(5, $this->runAgent($instance)->units);
        $this->assertSame(2, $this->units());
    }

    public function test_an_accepted_run_calls_models_and_posts_its_result_with_the_run_token(): void
    {
        $this->reply = Http::response(['accepted' => true], 202);

        $run = $this->runAgent($this->competitorWatch());
        $this->assertSame([AgentRun::STATUS_RUNNING, 1, 9], [$run->status, $run->units, $this->units()]);
        $token = $this->sentPayload()['platform']['token'];
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/agent-api/v1/chat/completions', ['messages' => [['role' => 'user', 'content' => 'سلام']]], $auth)
            ->assertOk()
            ->assertJsonPath('model', 'gpt-4o-mini')
            ->assertJsonPath('choices.0.message.content', 'تحلیل');
        $log = UsageLog::query()->sole();
        $this->assertSame([$run->id, 0], [$log->agent_run_id, $log->charge]);
        $this->assertGreaterThan(0, $log->cost);

        $this->postJson('/agent-api/v1/chat/completions', ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'x']]], $auth)->assertForbidden();
        $this->postJson('/agent-api/v1/chat/completions', ['stream' => true, 'messages' => [['role' => 'user', 'content' => 'x']]], $auth)->assertUnprocessable();
        $this->postJson('/agent-api/v1/chat/completions', ['messages' => [['role' => 'user', 'content' => 'x']]], ['Authorization' => 'Bearer agr_wrong'])->assertUnauthorized();

        $this->postJson('/agent-api/runs/'.($run->id + 1).'/result', ['status' => 'empty'], $auth)->assertForbidden();
        $this->postJson("/agent-api/runs/{$run->id}/result", ['status' => 'succeeded'], $auth)->assertUnprocessable();

        $this->postJson("/agent-api/runs/{$run->id}/result", ['status' => 'succeeded', 'report' => '## گزارش', 'units' => 2], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('units', 2);

        $run->refresh();
        $this->assertSame([AgentRun::STATUS_SUCCEEDED, 8], [$run->status, $this->units()]);
        $this->assertSame($log->cost, $run->cost);

        // The token stops working once the result is in.
        $this->postJson("/agent-api/runs/{$run->id}/result", ['status' => 'succeeded', 'report' => 'again'], $auth)->assertUnauthorized();
    }

    public function test_failures_and_missed_deadlines_give_the_unit_back(): void
    {
        $instance = $this->competitorWatch();

        $this->reply = Http::response('down', 500);
        $run = $this->runAgent($instance);
        $this->assertSame([AgentRun::STATUS_FAILED, 10], [$run->status, $this->units()]);
        $this->assertStringContainsString('500', $run->error);

        $this->reply = Http::response(['status' => 'failed', 'error' => 'سایت رقیب در دسترس نبود.']);
        $this->assertSame('سایت رقیب در دسترس نبود.', $this->runAgent($instance)->error);

        $this->reply = Http::response(['status' => 'empty', 'state' => ['cursor' => 9]]);
        $this->assertSame(AgentRun::STATUS_EMPTY, $this->runAgent($instance)->status);
        $this->assertSame([10, ['cursor' => 9]], [$this->units(), $instance->refresh()->state]);

        $this->reply = Http::response([], 202);
        $run = $this->runAgent($instance);
        $token = $this->sentPayload()['platform']['token'];
        $this->assertSame(9, $this->units());

        $this->travel(16)->minutes();
        $this->artisan('agents:run-due')->assertSuccessful();

        $this->assertSame(AgentRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertSame(10, $this->units());
        $this->postJson("/agent-api/runs/{$run->id}/result", ['status' => 'succeeded', 'report' => 'late'], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_a_manual_run_carries_the_input_the_agent_asks_for(): void
    {
        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Developer)->create());
        $instance = $this->competitorWatch();
        $this->reply = Http::response(['status' => 'succeeded', 'report' => 'خلاصه', 'units' => 1]);

        // Without a label the agent takes no input.
        $this->postJson("/api/v1/agent-instances/{$instance->id}/run", ['input' => 'x'])->assertJsonValidationErrors('input');

        $this->agent->update(['run_input_label' => 'لینکی که باید خلاصه شود']);
        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.run_input_label', 'لینکی که باید خلاصه شود');
        $this->postJson("/api/v1/agent-instances/{$instance->id}/run")->assertJsonValidationErrors('input');

        $this->postJson("/api/v1/agent-instances/{$instance->id}/run", ['input' => 'https://news.test/a'])->assertStatus(202)
            ->assertJsonPath('data.input', 'https://news.test/a');

        $this->assertSame('https://news.test/a', $this->sentPayload()['input']);
        $this->assertSame('https://news.test/a', AgentRun::query()->sole()->input);
    }

    public function test_ping_signs_the_request(): void
    {
        $this->reply = Http::response(['ok' => true]);
        $this->assertNull(app(HttpAgent::class)->ping($this->agent));
        $this->assertSame('ping', $this->sentPayload()['event']);

        $this->reply = Http::response('no', 401);
        $this->assertSame('سرویس پاسخ 401 داد.', app(HttpAgent::class)->ping($this->agent));
    }
}
