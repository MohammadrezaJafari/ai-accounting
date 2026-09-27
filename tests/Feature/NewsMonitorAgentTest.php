<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentCredit;
use App\Models\AgentCreditTransaction;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Models\AiModel;
use App\Models\App;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\UsageLog;
use App\Models\User;
use App\Notifications\AgentRunNotification;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\UrlGuard;
use App\Support\Money;
use App\Support\OrganizationRole;
use Database\Seeders\AgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsMonitorAgentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private App $wallet;

    private Agent $agent;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = Provider::query()->create(['slug' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.test/v1']);
        $provider->keys()->create(['name' => 'main', 'api_key' => 'sk-real']);
        AiModel::query()->create([
            'provider_id' => $provider->id, 'name' => 'GPT-4o mini', 'public_id' => 'gpt-4o-mini', 'upstream_id' => 'gpt-4o-mini-upstream',
            'input_price' => Money::fromUsd('1'), 'output_price' => Money::fromUsd('2'),
        ]);
        $this->seed(AgentSeeder::class);
        $this->agent = Agent::query()->where('slug', Agent::NEWS_MONITOR)->sole();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->inOrganization($this->organization)->create();
        $this->wallet = $this->organization->apps()->create(['name' => 'Wallet']);
        $this->wallet->forceFill(['balance' => Money::fromUsd('50')])->save();

        // Every source host resolves to a public address unless a test says otherwise.
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['93.184.216.34']));
    }

    private function buyCredits(int $units = 30, string $price = '9'): void
    {
        AgentCredit::query()->create([
            'organization_id' => $this->organization->id, 'agent_id' => $this->agent->id,
            'units' => $units, 'value' => Money::fromUsd($price),
        ]);
    }

    private function monitor(array $config = [], array $hours = []): AgentInstance
    {
        return $this->organization->agentInstances()->create([
            'agent_id' => $this->agent->id,
            'app_id' => $this->wallet->id,
            'name' => 'پایش فین‌تک',
            'config' => $config + ['sources' => ['https://news.test/rss'], 'keywords' => [], 'max_items' => 12],
            'run_hours' => $hours,
        ]);
    }

    private function fakeSources(string $reply = "## خلاصه\nگزارش آزمایشی"): void
    {
        Http::fake([
            'news.test/rss' => Http::response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <rss version="2.0"><channel><title>News</title>
                  <item><title>بانک مرکزی نرخ بهره را تغییر داد</title><link>https://news.test/a</link><description>&lt;p&gt;جزئیات تصمیم تازه&lt;/p&gt;</description><pubDate>Mon, 05 Oct 2026 08:00:00 GMT</pubDate></item>
                  <item><title>رشد استارتاپ‌های پرداخت در سال جاری</title><link>https://news.test/b</link><description>گزارش بازار پرداخت</description><pubDate>Mon, 05 Oct 2026 09:00:00 GMT</pubDate></item>
                  <item><title>نتایج لیگ فوتبال</title><link>https://news.test/c</link><description>ورزشی</description></item>
                </channel></rss>
                XML),
            'site.test/*' => Http::response('<html><body><a href="/story/1">یک تیتر خبری طولانی دربارهٔ بازار ارز و پرداخت</a><a href="/about">درباره</a></body></html>'),
            'api.openai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $reply]]],
                'usage' => ['prompt_tokens' => 20_000, 'completion_tokens' => 5_000],
            ]),
        ]);
    }

    private function prompt(ClientRequest $request): string
    {
        return (string) data_get($request->data(), 'messages.1.content', '');
    }

    public function test_billing_buys_a_package_from_an_app_wallet(): void
    {
        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Billing)->create());
        $package = $this->agent->packages()->where('units', 30)->sole();

        $this->getJson('/api/v1/agents')
            ->assertOk()
            ->assertJsonPath('data.0.slug', Agent::NEWS_MONITOR)
            ->assertJsonPath('data.0.credits', 0)
            ->assertJsonPath('data.0.packages.0.unit_price', '0.30');

        $this->postJson("/api/v1/agents/{$this->agent->id}/purchase", ['package_id' => $package->id, 'app_id' => $this->wallet->id])
            ->assertOk()
            ->assertJsonPath('credits', 30)
            ->assertJsonPath('app_balance', '41.00');

        $this->assertSame('agent_purchase', $this->wallet->transactions()->sole()->type);
        $this->assertSame(AgentCreditTransaction::TYPE_PURCHASE, AgentCreditTransaction::query()->sole()->type);

        $this->wallet->forceFill(['balance' => Money::fromUsd('5')])->save();
        $this->postJson("/api/v1/agents/{$this->agent->id}/purchase", ['package_id' => $package->id, 'app_id' => $this->wallet->id])
            ->assertJsonValidationErrors('app_id');

        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Developer)->create());
        $this->postJson("/api/v1/agents/{$this->agent->id}/purchase", ['package_id' => $package->id, 'app_id' => $this->wallet->id])->assertForbidden();
    }

    public function test_a_report_uses_one_unit_and_is_logged_at_cost(): void
    {
        Notification::fake();
        $this->fakeSources();
        $this->buyCredits();
        $instance = $this->monitor(['keywords' => ['بانک', 'پرداخت'], 'instructions' => 'روی فین‌تک تمرکز کن']);

        $runner = app(AgentRunner::class);
        $run = $runner->execute($runner->start($instance, AgentRun::TRIGGER_MANUAL));

        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        $this->assertSame(2, $run->items_found);
        $this->assertStringContainsString('گزارش آزمایشی', $run->report);

        // $9 / 30 reports = $0.30 revenue; 20k input × $1/M + 5k output × $2/M = $0.03 cost.
        $this->assertSame('0.30', Money::toUsd($run->revenue));
        $this->assertSame('0.03', Money::toUsd($run->cost));
        $log = UsageLog::query()->sole();
        $this->assertSame($run->id, $log->agent_run_id);
        $this->assertSame(0, $log->charge);
        $this->assertSame('50.00', Money::toUsd($this->wallet->refresh()->balance));

        $credit = AgentCredit::query()->sole();
        $this->assertSame(29, $credit->units);
        $this->assertSame('8.70', Money::toUsd($credit->value));
        $this->assertSame(-1, AgentCreditTransaction::query()->where('type', 'usage')->sole()->units);

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'api.openai.test')
            && str_contains($this->prompt($request), 'بانک مرکزی')
            && str_contains($this->prompt($request), 'روی فین‌تک تمرکز کن')
            && ! str_contains($this->prompt($request), 'فوتبال'));
        Notification::assertSentTo($this->owner, AgentRunNotification::class);
    }

    public function test_nothing_new_uses_no_unit_and_calls_no_model(): void
    {
        $this->fakeSources();
        $this->buyCredits();
        $instance = $this->monitor();
        $runner = app(AgentRunner::class);

        $runner->execute($runner->start($instance, AgentRun::TRIGGER_MANUAL));
        $second = $runner->execute($runner->start($instance->refresh(), AgentRun::TRIGGER_MANUAL));

        $this->assertSame(AgentRun::STATUS_EMPTY, $second->status);
        $this->assertSame(0, $second->units);
        $this->assertSame(29, AgentCredit::query()->sole()->units);
        $this->assertSame(1, UsageLog::query()->count());
    }

    public function test_a_finished_run_is_never_executed_again(): void
    {
        $this->fakeSources();
        $this->buyCredits();
        $runner = app(AgentRunner::class);
        $run = $runner->execute($runner->start($this->monitor(), AgentRun::TRIGGER_MANUAL));

        $again = $runner->execute($run);

        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $again->status);
        $this->assertSame(29, AgentCredit::query()->sole()->units);
        $this->assertSame(1, UsageLog::query()->count());
    }

    public function test_news_pages_without_a_feed_are_read_for_headlines(): void
    {
        $this->fakeSources();
        $this->buyCredits();
        $instance = $this->monitor(['sources' => ['https://site.test/news']]);

        $runner = app(AgentRunner::class);
        $run = $runner->execute($runner->start($instance, AgentRun::TRIGGER_MANUAL));

        $this->assertSame(1, $run->items_found);
        Http::assertSent(fn (ClientRequest $request) => str_contains($this->prompt($request), 'https://site.test/story/1'));
    }

    public function test_runs_without_units_are_skipped_and_owners_told_once(): void
    {
        Notification::fake();
        Http::fake();
        $instance = $this->monitor();
        $runner = app(AgentRunner::class);

        $first = $runner->execute($runner->start($instance, AgentRun::TRIGGER_SCHEDULE));
        $runner->execute($runner->start($instance, AgentRun::TRIGGER_SCHEDULE));

        $this->assertSame(AgentRun::STATUS_NO_CREDITS, $first->status);
        Http::assertNothingSent();
        Notification::assertSentToTimes($this->owner, AgentRunNotification::class, 1);
    }

    public function test_failed_runs_give_the_unit_back(): void
    {
        Http::fake([
            'news.test/*' => Http::response('<rss><channel><item><title>خبر</title><link>https://news.test/x</link></item></channel></rss>'),
            'api.openai.test/*' => Http::response(['error' => ['message' => 'overloaded']], 529),
        ]);
        $this->buyCredits();
        $runner = app(AgentRunner::class);

        $run = $runner->execute($runner->start($this->monitor(), AgentRun::TRIGGER_MANUAL));

        $this->assertSame(AgentRun::STATUS_FAILED, $run->status);
        $this->assertSame(0, $run->units);
        $this->assertSame(30, AgentCredit::query()->sole()->units);
        $this->assertSame('9.00', Money::toUsd(AgentCredit::query()->sole()->value));
    }

    public function test_a_run_stops_at_the_cost_cap(): void
    {
        $this->fakeSources();
        $this->buyCredits();
        $this->agent->update(['max_cost_per_run' => 0]);
        $runner = app(AgentRunner::class);

        $run = $runner->execute($runner->start($this->monitor(), AgentRun::TRIGGER_MANUAL));

        $this->assertSame(AgentRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('سقف', $run->error);
        $this->assertSame(0, UsageLog::query()->count());
    }

    public function test_sources_on_the_internal_network_are_refused(): void
    {
        Http::fake();
        $this->buyCredits();
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => $host === 'intranet.test' ? ['10.0.0.5'] : ['93.184.216.34']));
        $runner = app(AgentRunner::class);

        $run = $runner->execute($runner->start($this->monitor(['sources' => ['http://127.0.0.1/admin', 'https://intranet.test/feed']]), AgentRun::TRIGGER_MANUAL));

        $this->assertSame(AgentRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('شبکهٔ داخلی', $run->error);
        Http::assertNothingSent();
    }

    public function test_scheduled_monitors_run_at_their_hour(): void
    {
        $this->fakeSources();
        $this->buyCredits();
        $this->travelTo('2026-10-07 03:00:00'); // 06:30 Tehran
        $instance = $this->monitor(hours: [8, 20]);
        $this->assertSame('2026-10-07 04:30:00', $instance->next_run_at->format('Y-m-d H:i:s'));

        $this->artisan('agents:run-due')->assertSuccessful();
        $this->assertSame(0, AgentRun::query()->count());

        $this->travelTo('2026-10-07 04:31:00');
        $this->artisan('agents:run-due')->assertSuccessful();

        $this->assertSame(AgentRun::STATUS_SUCCEEDED, AgentRun::query()->sole()->status);
        $this->assertSame('2026-10-07 16:30:00', $instance->refresh()->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_members_configure_run_and_read_monitors_through_the_api(): void
    {
        Notification::fake();
        $this->fakeSources();
        $this->buyCredits();
        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Developer)->create());

        $this->postJson('/api/v1/agent-instances', ['agent_id' => $this->agent->id, 'app_id' => $this->wallet->id, 'name' => 'x', 'config' => ['sources' => ['not a url']]])
            ->assertJsonValidationErrors('config.sources.0');

        $id = $this->postJson('/api/v1/agent-instances', [
            'agent_id' => $this->agent->id, 'app_id' => $this->wallet->id, 'name' => 'پایش بازار',
            'config' => ['sources' => ['https://news.test/rss'], 'keywords' => ['بانک']], 'run_hours' => [9],
        ])->assertCreated()->assertJsonPath('data.config.max_items', 12)->json('data.id');

        $runId = $this->postJson("/api/v1/agent-instances/{$id}/run")->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.id');

        // The run executes after the response.
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, AgentRun::query()->find($runId)->status);

        $this->getJson("/api/v1/agent-instances/{$id}/runs")->assertOk()->assertJsonPath('data.0.status', 'succeeded')->assertJsonMissingPath('data.0.report');
        $this->getJson("/api/v1/agent-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.items_found', 1)
            ->assertJsonMissingPath('data.cost')
            ->assertJsonMissingPath('data.revenue');

        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Billing)->create());
        $this->getJson("/api/v1/agent-runs/{$runId}")->assertOk();
        $this->postJson("/api/v1/agent-instances/{$id}/run")->assertForbidden();

        Sanctum::actingAs(User::factory()->inOrganization()->create());
        $this->getJson("/api/v1/agent-runs/{$runId}")->assertNotFound();
        $this->getJson("/api/v1/agent-instances/{$id}")->assertNotFound();
    }
}
