<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\App;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\UsageLog;
use App\Services\ApiKeyService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayTest extends TestCase
{
    use RefreshDatabase;

    private App $clientApp;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.default_markup_bps' => 2000]);

        $openai = Provider::query()->create(['slug' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.test/v1']);
        $anthropic = Provider::query()->create([
            'slug' => 'anthropic', 'name' => 'Anthropic', 'base_url' => 'https://api.anthropic.test/v1',
            'native_format' => 'anthropic', 'native_base_url' => 'https://api.anthropic.test',
        ]);
        $openai->keys()->create(['name' => 'main', 'api_key' => 'sk-real-openai']);
        $anthropic->keys()->create(['name' => 'main', 'api_key' => 'sk-ant-real']);

        AiModel::query()->create([
            'provider_id' => $openai->id, 'name' => 'GPT', 'public_id' => 'gpt-test', 'upstream_id' => 'gpt-upstream',
            'input_price' => Money::fromUsd('1'), 'output_price' => Money::fromUsd('2'),
        ]);
        AiModel::query()->create([
            'provider_id' => $anthropic->id, 'name' => 'Claude', 'public_id' => 'claude-test', 'upstream_id' => 'claude-upstream',
            'input_price' => Money::fromUsd('3'), 'output_price' => Money::fromUsd('15'),
            'cached_input_price' => Money::fromUsd('0.3'), 'cache_write_price' => Money::fromUsd('3.75'),
        ]);

        $this->clientApp = Organization::factory()->create()->apps()->create(['name' => 'My app']);
        $this->clientApp->forceFill(['balance' => Money::fromUsd('10')])->save();
        [, $this->key] = app(ApiKeyService::class)->create($this->clientApp, ['name' => 'default']);
    }

    public function test_chat_completion_is_proxied_and_billed(): void
    {
        Http::fake(['api.openai.test/*' => Http::response([
            'id' => 'chatcmpl-1', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]],
            'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000],
        ])]);

        $this->withToken($this->key)
            ->postJson('/v1/chat/completions', ['model' => 'gpt-test', 'messages' => [['role' => 'user', 'content' => 'Hello']], 'tools' => [['type' => 'function', 'function' => ['name' => 'f', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]]]]])
            ->assertOk()
            ->assertJsonPath('choices.0.message.content', 'Hi');

        Http::assertSent(function (ClientRequest $request) {
            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-real-openai')
                && $request['model'] === 'gpt-upstream'
                && str_contains($request->body(), '"properties":{}');
        });

        // cost = $1 + $1 = $2, charge = $2.40
        $log = UsageLog::query()->sole();
        $this->assertSame('2.00', Money::toUsd($log->cost));
        $this->assertSame('2.40', Money::toUsd($log->charge));
        $this->assertSame('7.60', Money::toUsd($this->clientApp->refresh()->balance));
    }

    public function test_streamed_chat_completion_bills_final_usage_chunk(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n"
            ."data: {\"choices\":[],\"usage\":{\"prompt_tokens\":100000,\"completion_tokens\":100000,\"prompt_tokens_details\":{\"cached_tokens\":0}}}\n\n"
            ."data: [DONE]\n\n";

        Http::fake(['api.openai.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $response = $this->withToken($this->key)
            ->postJson('/v1/chat/completions', ['model' => 'gpt-test', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'Hi']]]);

        $this->assertStringContainsString('"Hel"', $response->streamedContent());

        Http::assertSent(fn (ClientRequest $r) => $r['stream_options']['include_usage'] === true);

        // $0.10 + $0.20 = $0.30 cost, $0.36 charge
        $this->assertSame('0.36', Money::toUsd(UsageLog::query()->sole()->charge));
    }

    public function test_anthropic_messages_stream_is_billed_with_cache_tokens(): void
    {
        $sse = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":1000000,\"cache_read_input_tokens\":1000000,\"cache_creation_input_tokens\":0,\"output_tokens\":1}}}\n\n"
            ."event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n"
            ."event: message_delta\ndata: {\"type\":\"message_delta\",\"usage\":{\"output_tokens\":100000}}\n\n";

        Http::fake(['api.anthropic.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $this->withHeaders(['x-api-key' => $this->key, 'anthropic-version' => '2023-06-01'])
            ->postJson('/v1/messages', ['model' => 'claude-test', 'max_tokens' => 10, 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'Hi']]])
            ->streamedContent();

        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://api.anthropic.test/v1/messages'
            && $r->hasHeader('x-api-key', 'sk-ant-real')
            && $r['model'] === 'claude-upstream');

        // $3 input + $0.30 cache read + $1.50 output = $4.80 cost
        $log = UsageLog::query()->sole();
        $this->assertSame(100000, $log->output_tokens);
        $this->assertSame('4.80', Money::toUsd($log->cost));
        $this->assertSame('5.76', Money::toUsd($log->charge));
    }

    public function test_openai_models_are_rejected_on_the_anthropic_endpoint(): void
    {
        $this->withHeaders(['x-api-key' => $this->key])
            ->postJson('/v1/messages', ['model' => 'gpt-test', 'max_tokens' => 10, 'messages' => []])
            ->assertStatus(400)
            ->assertJsonPath('type', 'error');
    }

    public function test_upstream_errors_are_passed_through_and_not_billed(): void
    {
        Http::fake(['api.openai.test/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        $this->withToken($this->key)
            ->postJson('/v1/chat/completions', ['model' => 'gpt-test', 'messages' => []])
            ->assertStatus(400)
            ->assertJsonPath('error.message', 'bad');

        $this->assertSame(0, UsageLog::query()->sole()->charge);
        $this->assertSame('10.00', Money::toUsd($this->clientApp->refresh()->balance));
    }

    public function test_requests_are_rejected_without_balance(): void
    {
        $this->clientApp->forceFill(['balance' => 0])->save();

        $this->withToken($this->key)
            ->postJson('/v1/chat/completions', ['model' => 'gpt-test', 'messages' => []])
            ->assertStatus(402);
    }

    public function test_invalid_key_is_rejected(): void
    {
        $this->withToken('sk-aia-nope')->getJson('/v1/models')->assertStatus(401);
    }

    public function test_key_restrictions_limit_models(): void
    {
        [, $claudeOnly] = app(ApiKeyService::class)->create($this->clientApp, ['name' => 'claude', 'allowed_providers' => ['anthropic']]);

        $this->withToken($claudeOnly)->getJson('/v1/models')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'claude-test');

        $this->withToken($claudeOnly)
            ->postJson('/v1/chat/completions', ['model' => 'gpt-test', 'messages' => []])
            ->assertStatus(403);
    }

    public function test_key_spend_limit(): void
    {
        [$key, $plain] = app(ApiKeyService::class)->create($this->clientApp, ['name' => 'limited', 'spend_limit' => Money::fromUsd('1')]);
        $key->forceFill(['spent' => Money::fromUsd('1'), 'period_spent' => Money::fromUsd('1')])->save();

        $this->withToken($plain)->getJson('/v1/models')->assertStatus(402);
    }
}
