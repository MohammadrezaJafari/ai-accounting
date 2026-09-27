<?php

namespace Tests\Feature;

use App\Mail\AgentReportMail;
use App\Models\Agent;
use App\Models\AgentCredit;
use App\Models\AgentDestination;
use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Models\AiModel;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\User;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\AgentSchedule;
use App\Services\Agents\UrlGuard;
use App\Support\DeliveryChannel;
use App\Support\Money;
use App\Support\OrganizationRole;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456:ABCdefGHIjklMNOpqrSTUvwxYZ012345';

    private Organization $organization;

    private AgentInstance $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.api_url' => 'https://tg.test',
            'services.telegram.bot_token' => self::BOT_TOKEN,
            'services.bale.api_url' => 'https://bale.test',
        ]);

        $provider = Provider::query()->create(['slug' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.test/v1']);
        $provider->keys()->create(['name' => 'main', 'api_key' => 'sk-real']);
        AiModel::query()->create([
            'provider_id' => $provider->id, 'name' => 'mini', 'public_id' => 'gpt-4o-mini', 'upstream_id' => 'gpt-4o-mini',
            'input_price' => Money::fromUsd('1'), 'output_price' => Money::fromUsd('2'),
        ]);
        $this->seed(AgentSeeder::class);
        $agent = Agent::query()->sole();

        $this->organization = Organization::factory()->create();
        $app = $this->organization->apps()->create(['name' => 'Wallet']);
        AgentCredit::query()->create(['organization_id' => $this->organization->id, 'agent_id' => $agent->id, 'units' => 10, 'value' => Money::fromUsd('3')]);
        $this->monitor = $this->organization->agentInstances()->create([
            'agent_id' => $agent->id, 'app_id' => $app->id, 'name' => 'پایش بازار',
            'config' => ['sources' => ['https://news.test/rss']],
        ]);

        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => $host === 'intranet.test' ? ['192.168.1.10'] : ['93.184.216.34']));
    }

    private function fake(array $extra = []): void
    {
        Http::fake($extra + [
            'news.test/*' => Http::response('<rss><channel><item><title>خبر مهم بازار</title><link>https://news.test/a</link></item></channel></rss>'),
            'api.openai.test/*' => Http::response([
                'choices' => [['message' => ['content' => "## خلاصه\nبازار **رشد** کرد.\n\n## خبرها\n### ۱. خبر مهم\n[منبع](https://news.test/a)"]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
            'tg.test/*' => Http::response(['ok' => true, 'result' => []]),
            'bale.test/*' => Http::response(['ok' => true, 'result' => []]),
            'hooks.test/*' => Http::response(['received' => true]),
        ]);
    }

    private function destination(DeliveryChannel $type, array $settings): AgentDestination
    {
        return $this->monitor->destinations()->create(['type' => $type, 'label' => $type->label(), 'settings' => $settings]);
    }

    private function runMonitor(): AgentRun
    {
        $runner = app(AgentRunner::class);

        return $runner->execute($runner->start($this->monitor->refresh(), AgentRun::TRIGGER_MANUAL));
    }

    public function test_reports_reach_telegram_bale_email_and_webhooks(): void
    {
        Mail::fake();
        $this->fake();
        $this->destination(DeliveryChannel::Telegram, ['chat_id' => '@market_news']);
        $this->destination(DeliveryChannel::Bale, ['chat_id' => '4455667788', 'bot_token' => '987654:BaleOwnBotTokenValue1234567']);
        $this->destination(DeliveryChannel::Email, ['emails' => ['ceo@example.com']]);
        $hook = $this->destination(DeliveryChannel::Webhook, ['url' => 'https://hooks.test/in', 'secret' => 'shh']);

        $run = $this->runMonitor();

        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        $this->assertSame([true, true, true, true], array_column($run->meta['deliveries'], 'ok'));

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://tg.test/bot'.self::BOT_TOKEN.'/sendMessage'
            && $request['chat_id'] === '@market_news'
            && $request['parse_mode'] === 'HTML'
            && str_contains($request['text'], '<b>پایش بازار — ')
            && str_contains($request['text'], '<strong>رشد</strong>')
            && str_contains($request['text'], '<a href="https://news.test/a">منبع</a>'));

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://bale.test/bot987654:BaleOwnBotTokenValue1234567/sendMessage'
            && ! isset($request['parse_mode'])
            && str_contains($request['text'], 'بازار رشد کرد.')
            && str_contains($request['text'], 'منبع: https://news.test/a')
            && ! str_contains($request['text'], '##'));

        Mail::assertSent(AgentReportMail::class, fn (AgentReportMail $mail) => $mail->hasTo('ceo@example.com') && str_contains($mail->reportHtml, '<strong>رشد</strong>'));

        Http::assertSent(function (ClientRequest $request) {
            return $request->url() === 'https://hooks.test/in'
                && $request['event'] === 'agent.report'
                && str_contains($request['report'], 'بازار **رشد** کرد')
                && $request->header('X-Signature')[0] === hash_hmac('sha256', $request->body(), 'shh');
        });
        $this->assertNotNull($hook->refresh()->last_delivered_at);
    }

    public function test_a_failing_destination_does_not_fail_the_run(): void
    {
        $this->fake(['tg.test/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
        $telegram = $this->destination(DeliveryChannel::Telegram, ['chat_id' => '@missing_channel']);
        $this->destination(DeliveryChannel::Webhook, ['url' => 'https://intranet.test/hook', 'secret' => 'x']);

        $run = $this->runMonitor();

        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame([false, false], array_column($run->meta['deliveries'], 'ok'));
        $this->assertStringContainsString('پیدا نشد', $telegram->refresh()->last_error);
        $this->assertStringContainsString('شبکهٔ داخلی', $run->meta['deliveries'][1]['error']);
        $this->assertSame(9, AgentCredit::query()->sole()->units);
    }

    public function test_empty_runs_are_announced_only_when_asked(): void
    {
        $this->fake(['news.test/*' => Http::response('<rss><channel></channel></rss>')]);
        $this->destination(DeliveryChannel::Telegram, ['chat_id' => '@market_news']);

        $this->runMonitor();
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'tg.test'));

        $this->monitor->update(['notify_empty' => true]);
        $run = $this->runMonitor();

        $this->assertSame(AgentRun::STATUS_EMPTY, $run->status);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'tg.test') && str_contains($request['text'], 'مورد تازه‌ای پیدا نشد'));
    }

    public function test_destinations_are_managed_and_tested_through_the_api(): void
    {
        $this->fake();
        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Developer)->create());

        $this->postJson("/api/v1/agent-instances/{$this->monitor->id}/destinations", ['type' => 'telegram', 'settings' => ['chat_id' => 'not a chat']])
            ->assertJsonValidationErrors('settings.chat_id');
        $this->postJson("/api/v1/agent-instances/{$this->monitor->id}/destinations", ['type' => 'bale', 'settings' => ['chat_id' => '@news_channel']])
            ->assertJsonValidationErrors('settings.bot_token');

        $bale = $this->postJson("/api/v1/agent-instances/{$this->monitor->id}/destinations", [
            'type' => 'bale', 'label' => 'کانال بله', 'settings' => ['chat_id' => '@news_channel', 'bot_token' => '987654:BaleOwnBotTokenValue1234567'],
        ])->assertCreated()->assertJsonPath('data.settings.bot_token', '987654:…4567')->json('data.id');

        $hook = $this->postJson("/api/v1/agent-instances/{$this->monitor->id}/destinations", ['type' => 'webhook', 'settings' => ['url' => 'https://hooks.test/in']])
            ->assertCreated()->json('data');
        $this->assertSame(40, strlen($hook['settings']['secret']));

        // An empty token on update keeps the stored one.
        $this->patchJson("/api/v1/agent-destinations/{$bale}", ['settings' => ['chat_id' => '@renamed_channel', 'bot_token' => '']])->assertOk();
        $this->assertSame('987654:BaleOwnBotTokenValue1234567', AgentDestination::query()->find($bale)->setting('bot_token'));

        $this->postJson("/api/v1/agent-destinations/{$bale}/test")->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'bale.test') && $request['chat_id'] === '@renamed_channel');

        $this->getJson("/api/v1/agent-instances/{$this->monitor->id}")
            ->assertJsonCount(2, 'data.destinations')
            ->assertJsonMissingPath('data.destinations.0.settings.bot_token_plain');

        Sanctum::actingAs(User::factory()->inOrganization($this->organization, OrganizationRole::Billing)->create());
        $this->getJson("/api/v1/agent-instances/{$this->monitor->id}/destinations")->assertOk()->assertJsonMissingPath('data.1.settings.secret');
        $this->postJson("/api/v1/agent-destinations/{$bale}/test")->assertForbidden();

        Sanctum::actingAs(User::factory()->inOrganization()->create());
        $this->deleteJson("/api/v1/agent-destinations/{$bale}")->assertNotFound();
    }

    public function test_bot_tokens_are_stored_encrypted(): void
    {
        $this->destination(DeliveryChannel::Telegram, ['chat_id' => '@a_channel', 'bot_token' => self::BOT_TOKEN]);

        $raw = (string) \DB::table('agent_destinations')->value('settings');
        $this->assertStringNotContainsString(self::BOT_TOKEN, $raw);
    }

    public function test_runs_can_be_limited_to_working_days(): void
    {
        // Friday 2026-10-09 10:00 Tehran; runs at 8:00 Saturday–Wednesday.
        $next = AgentSchedule::next([8], [6, 0, 1, 2, 3], CarbonImmutable::parse('2026-10-09 06:30:00', 'UTC'));

        $this->assertSame('2026-10-10 04:30:00', $next->format('Y-m-d H:i:s'));
        $this->assertTrue($next->setTimezone('Asia/Tehran')->isSaturday());
    }
}
