<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentCredit;
use App\Models\AgentRun;
use App\Models\AiModel;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\PublisherPayout;
use App\Models\User;
use App\Notifications\PublisherReviewNotification;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\UrlGuard;
use App\Services\Publishers\PublisherService;
use App\Support\AgentStatus;
use App\Support\Money;
use App\Support\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublisherTest extends TestCase
{
    use RefreshDatabase;

    private Organization $publisher;

    /** What the publisher's service answers next. */
    private mixed $reply = null;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->publisher = Organization::factory()->create(['name' => 'استودیو نوآ']);
        Sanctum::actingAs(User::factory()->inOrganization($this->publisher, OrganizationRole::Developer)->create());

        // Publisher hosts resolve to a public address, except the internal one.
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => $host === 'internal.test' ? ['10.0.0.5'] : ['93.184.216.34']));
        Http::fake([
            'agent.noa.test/*' => fn () => $this->reply,
            'api.openai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
                'usage' => ['prompt_tokens' => 50_000, 'completion_tokens' => 0],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(array $overrides = []): array
    {
        return $overrides + [
            'slug' => 'lead-finder',
            'name' => 'یابندهٔ مشتری',
            'tagline' => 'هر روز مشتری‌های بالقوه پیدا می‌کند',
            'description' => 'توضیح کامل',
            'icon' => 'person_search',
            'category' => 'فروش',
            'unit_name' => 'مشتری بالقوه',
            'max_units_per_run' => 10,
            'endpoint_url' => 'https://agent.noa.test/run',
            'config_schema' => [
                ['key' => 'industry', 'label' => 'صنعت', 'type' => 'text', 'required' => true],
            ],
            'packages' => [['units' => 50, 'price' => '10'], ['units' => 200, 'price' => '30']],
        ];
    }

    private function createListing(array $overrides = []): Agent
    {
        $id = $this->postJson('/api/v1/publisher/agents', $this->listing($overrides))->assertCreated()->json('data.id');

        return Agent::query()->findOrFail($id);
    }

    public function test_a_publisher_drafts_tests_and_submits_a_listing(): void
    {
        $this->postJson('/api/v1/publisher/agents', $this->listing(['endpoint_url' => 'https://internal.test/run', 'config_schema' => [['key' => 'Bad Key']]]))
            ->assertJsonValidationErrors(['endpoint_url', 'config_schema']);

        $agent = $this->createListing();
        $this->assertSame([AgentStatus::Draft, false, 70], [$agent->status, $agent->is_active, $agent->revenue_share]);
        $this->assertSame($this->publisher->id, $agent->publisher_organization_id);

        $this->getJson("/api/v1/publisher/agents/{$agent->id}")->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.packages', [['units' => 50, 'price' => '10.00'], ['units' => 200, 'price' => '30.00']])
            ->assertJsonPath('data.terms.max_cost_per_run', '0.10')
            ->assertJsonPath('data.signing_secret', $agent->signing_secret);

        // Not in the store until approved.
        $this->getJson('/api/v1/agents')->assertOk()->assertJsonCount(0, 'data');

        // A test run uses no credits and is checked against the listing's parameters.
        $this->postJson("/api/v1/publisher/agents/{$agent->id}/test-runs", ['config' => []])->assertJsonValidationErrors('config.industry');
        $this->reply = Http::response(['status' => 'succeeded', 'report' => '## ۳ مشتری', 'units' => 3, 'notes' => ['۳ شرکت پیدا شد.']]);
        $runId = $this->postJson("/api/v1/publisher/agents/{$agent->id}/test-runs", ['config' => ['industry' => 'فین‌تک']])
            ->assertStatus(202)->json('data.id');

        $this->getJson("/api/v1/publisher/agents/{$agent->id}/runs/{$runId}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.trigger', 'test')
            ->assertJsonPath('data.report', '## ۳ مشتری')
            ->assertJsonPath('data.units', 0);
        $this->assertSame('فین‌تک', json_decode(Http::recorded()->last()[0]->body(), true)['config']['industry']);

        $this->getJson('/api/v1/agent-instances')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/publisher/agents/{$agent->id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->patchJson("/api/v1/publisher/agents/{$agent->id}", ['name' => 'نام تازه'])->assertOk();
        $this->assertSame(['name' => 'نام تازه'], $agent->refresh()->pending_changes);
        app(PublisherService::class)->approve($agent);
        $this->assertSame(['نام تازه', null, AgentStatus::Approved], [$agent->refresh()->name, $agent->pending_changes, $agent->status]);

        // Billing members may not edit listings; other organizations do not see them.
        Sanctum::actingAs(User::factory()->inOrganization($this->publisher, OrganizationRole::Billing)->create());
        $this->getJson("/api/v1/publisher/agents/{$agent->id}")->assertForbidden();
        Sanctum::actingAs(User::factory()->inOrganization()->create());
        $this->getJson("/api/v1/publisher/agents/{$agent->id}")->assertNotFound();
    }

    public function test_changes_to_a_live_listing_wait_for_review(): void
    {
        $agent = $this->createListing();
        $publishers = app(PublisherService::class);
        $publishers->submit($agent);
        $publishers->approve($agent, 60);

        $this->getJson('/api/v1/agents')->assertOk()->assertJsonPath('data.0.publisher.name', 'استودیو نوآ');

        $this->patchJson("/api/v1/publisher/agents/{$agent->id}", [
            'tagline' => 'معرفی تازه',
            'endpoint_url' => 'https://agent.noa.test/v2',
            'packages' => [['units' => 50, 'price' => '12']],
        ])->assertOk()->assertJsonPath('data.pending_changes.tagline', 'معرفی تازه');

        $agent->refresh();
        $this->assertSame('هر روز مشتری‌های بالقوه پیدا می‌کند', $agent->tagline);

        // Test runs already use the pending endpoint.
        $this->reply = Http::response(['status' => 'empty']);
        $this->postJson("/api/v1/publisher/agents/{$agent->id}/test-runs", ['config' => ['industry' => 'x']])->assertStatus(202);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://agent.noa.test/v2');

        $publishers->applyChanges($agent);
        $agent->refresh();
        $this->assertSame(['معرفی تازه', 'https://agent.noa.test/v2', null], [$agent->tagline, $agent->endpoint_url, $agent->pending_changes]);
        $this->assertSame([12], $agent->packages()->where('is_active', true)->pluck('price')->map(fn ($price) => (int) Money::toUsd($price))->all());
        $this->assertSame(1, $agent->packages()->where('is_active', false)->count());

        $this->patchJson("/api/v1/publisher/agents/{$agent->id}", ['name' => 'اسم بد'])->assertOk();
        $publishers->reject($agent->refresh(), 'نام با محتوا نمی‌خواند.');
        Notification::assertSentTo(
            $this->publisher->members()->first(),
            PublisherReviewNotification::class,
            fn (PublisherReviewNotification $notification) => $notification->outcome === PublisherReviewNotification::CHANGES_REJECTED,
        );
        $this->getJson("/api/v1/publisher/agents/{$agent->id}")
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.pending_changes', null)
            ->assertJsonPath('data.review_note', 'نام با محتوا نمی‌خواند.');
        $this->deleteJson("/api/v1/publisher/agents/{$agent->id}")->assertUnprocessable();
    }

    public function test_the_publisher_earns_its_share_of_each_sale(): void
    {
        $agent = $this->createListing();
        $publishers = app(PublisherService::class);
        $publishers->approve($publishers->submit($agent), 70);

        $customer = Organization::factory()->create();
        User::factory()->inOrganization($customer)->create();
        $app = $customer->apps()->create(['name' => 'App']);
        AgentCredit::query()->create(['organization_id' => $customer->id, 'agent_id' => $agent->id, 'units' => 50, 'value' => Money::fromUsd('10')]);
        $instance = $customer->agentInstances()->create(['agent_id' => $agent->id, 'app_id' => $app->id, 'name' => 'مشتری‌یابی', 'config' => ['industry' => 'فین‌تک']]);

        $this->reply = Http::response(['status' => 'succeeded', 'report' => 'x', 'units' => 5]);
        $runner = app(AgentRunner::class);
        $run = $runner->execute($runner->start($instance, AgentRun::TRIGGER_MANUAL));

        // 5 units of a $10 / 50 package = $1.00 revenue, 70% to the publisher.
        $this->assertSame([5, Money::fromUsd('1'), Money::fromUsd('0.70')], [$run->units, $run->revenue, $run->publisher_share]);

        PublisherPayout::query()->create(['organization_id' => $this->publisher->id, 'amount' => Money::fromUsd('0.50'), 'reference' => 'TX-1', 'paid_at' => now()]);

        $response = $this->getJson('/api/v1/publisher')->assertOk()
            ->assertJsonPath('summary.units', 5)
            ->assertJsonPath('summary.revenue', '1.00')
            ->assertJsonPath('summary.earned', '0.70')
            ->assertJsonPath('summary.paid', '0.50')
            ->assertJsonPath('summary.balance', '0.20')
            ->assertJsonPath('summary.customers', 1)
            ->assertJsonPath('payouts.0.reference', 'TX-1')
            ->assertJsonPath('profile.payout_details', null);
        $this->assertSame('0.70', collect($response->json('daily'))->last()['earned']);

        $this->getJson("/api/v1/publisher/agents/{$agent->id}/runs/{$run->id}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.report', null);

        // Paid out more than earned: the balance may go $5 below zero before test runs stop.
        PublisherPayout::query()->create(['organization_id' => $this->publisher->id, 'amount' => Money::fromUsd('5'), 'paid_at' => now()]);
        $this->postJson("/api/v1/publisher/agents/{$agent->id}/test-runs", ['config' => ['industry' => 'x']])->assertStatus(202);
        PublisherPayout::query()->create(['organization_id' => $this->publisher->id, 'amount' => Money::fromUsd('1'), 'paid_at' => now()]);
        $this->getJson('/api/v1/publisher')->assertJsonPath('summary.balance', '-5.80')->assertJsonPath('test_runs_blocked', true);
        $this->getJson("/api/v1/publisher/agents/{$agent->id}")->assertJsonPath('data.terms.test_runs_blocked', true);
        $this->postJson("/api/v1/publisher/agents/{$agent->id}/test-runs", ['config' => ['industry' => 'x']])->assertJsonValidationErrors('run');

        $this->patchJson('/api/v1/publisher', ['publisher_name' => 'نوآ', 'payout_details' => 'IR000'])->assertForbidden();
        Sanctum::actingAs(User::factory()->inOrganization($this->publisher, OrganizationRole::Owner)->create());
        $this->patchJson('/api/v1/publisher', ['publisher_name' => 'نوآ', 'payout_details' => 'IR000'])->assertOk();
        $this->getJson('/api/v1/publisher')->assertJsonPath('profile.payout_details', 'IR000');
        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.publisher.name', 'نوآ');
    }

    public function test_model_costs_are_charged_to_the_publisher(): void
    {
        $provider = Provider::query()->create(['slug' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.test/v1']);
        $provider->keys()->create(['name' => 'main', 'api_key' => 'sk-real']);
        AiModel::query()->create([
            'provider_id' => $provider->id, 'name' => 'mini', 'public_id' => 'gpt-4o-mini', 'upstream_id' => 'gpt-4o-mini',
            'input_price' => Money::fromUsd('1'), 'output_price' => Money::fromUsd('2'),
        ]);

        $agent = $this->createListing();
        $agent->update(['model' => 'gpt-4o-mini']);
        $publishers = app(PublisherService::class);
        $publishers->approve($publishers->submit($agent), 70);

        // The publisher sets its own cap, up to the ceiling, and it applies without review.
        $this->patchJson("/api/v1/publisher/agents/{$agent->id}", ['max_cost_per_run' => 5])->assertJsonValidationErrors('max_cost_per_run');
        $this->patchJson("/api/v1/publisher/agents/{$agent->id}", ['max_cost_per_run' => '0.05'])->assertOk()
            ->assertJsonPath('data.terms.max_cost_per_run', '0.05')
            ->assertJsonPath('data.terms.max_cost_ceiling', '1.00')
            ->assertJsonPath('data.pending_changes', null);

        $customer = Organization::factory()->create();
        User::factory()->inOrganization($customer)->create();
        $app = $customer->apps()->create(['name' => 'App']);
        AgentCredit::query()->create(['organization_id' => $customer->id, 'agent_id' => $agent->id, 'units' => 50, 'value' => Money::fromUsd('10')]);
        $instance = $customer->agentInstances()->create(['agent_id' => $agent->id, 'app_id' => $app->id, 'name' => 'مشتری‌یابی', 'config' => ['industry' => 'x']]);
        $runner = app(AgentRunner::class);

        // The agent accepts, calls a model ($0.05 of input, which reaches the cap) and delivers 5 units.
        $this->reply = Http::response([], 202);
        $run = $runner->execute($runner->start($instance, AgentRun::TRIGGER_MANUAL));
        $auth = ['Authorization' => 'Bearer '.json_decode(Http::recorded()->last()[0]->body(), true)['platform']['token']];
        $this->postJson('/agent-api/v1/chat/completions', ['messages' => [['role' => 'user', 'content' => 'x']]], $auth)->assertOk();
        $this->postJson('/agent-api/v1/chat/completions', ['messages' => [['role' => 'user', 'content' => 'x']]], $auth)->assertForbidden();
        $this->postJson("/agent-api/runs/{$run->id}/result", ['status' => 'succeeded', 'report' => 'x', 'units' => 5], $auth)->assertOk();

        $run->refresh();
        $this->assertSame([Money::fromUsd('0.70'), Money::fromUsd('0.05'), Money::fromUsd('0.05')], [$run->publisher_share, $run->publisher_cost, $run->cost]);
        $this->assertSame(Money::fromUsd('0.30'), $run->margin());

        // A run that found nothing still costs the publisher its model calls.
        $this->reply = Http::response([], 202);
        $empty = $runner->execute($runner->start($instance, AgentRun::TRIGGER_MANUAL));
        $auth = ['Authorization' => 'Bearer '.json_decode(Http::recorded()->last()[0]->body(), true)['platform']['token']];
        $this->postJson('/agent-api/v1/chat/completions', ['messages' => [['role' => 'user', 'content' => 'x']]], $auth)->assertOk();
        $this->postJson("/agent-api/runs/{$empty->id}/result", ['status' => 'empty'], $auth)->assertOk();
        $this->assertSame([0, Money::fromUsd('0.05')], [$empty->refresh()->publisher_share, $empty->publisher_cost]);

        $this->getJson('/api/v1/publisher')->assertOk()
            ->assertJsonPath('summary.revenue', '1.00')
            ->assertJsonPath('summary.share', '0.70')
            ->assertJsonPath('summary.model_cost', '0.10')
            ->assertJsonPath('summary.earned', '0.60')
            ->assertJsonPath('summary.balance', '0.60');
        $this->getJson("/api/v1/publisher/agents/{$agent->id}")->assertOk()
            ->assertJsonPath('data.stats.share', '0.70')
            ->assertJsonPath('data.stats.cost', '0.10')
            ->assertJsonPath('data.stats.earned', '0.60')
            ->assertJsonPath('data.stats.avg_run_cost', '0.05');
    }
}
