<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AppApiKey;
use App\Models\Provider;
use App\Models\UsageLog;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlaygroundChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = Provider::query()->create(['slug' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.test/v1']);
        $provider->keys()->create(['name' => 'main', 'api_key' => 'sk-real']);
        AiModel::query()->create([
            'provider_id' => $provider->id, 'name' => 'GPT', 'public_id' => 'gpt-test', 'upstream_id' => 'gpt-test',
            'input_price' => Money::fromUsd('1'), 'output_price' => Money::fromUsd('2'),
        ]);
    }

    public function test_panel_chat_is_billed_to_the_app_under_a_panel_key(): void
    {
        Http::fake(['api.openai.test/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'سلام']]],
            'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 0],
        ])]);

        $user = User::factory()->inOrganization()->create();
        $app = $user->currentOrganization->apps()->create(['name' => 'App']);
        $app->forceFill(['balance' => Money::fromUsd('5')])->save();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/apps/{$app->id}/chat/completions", ['model' => 'gpt-test', 'messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertOk()
            ->assertJsonPath('choices.0.message.content', 'سلام');

        $this->postJson("/api/v1/apps/{$app->id}/chat/completions", ['model' => 'gpt-test', 'messages' => []])->assertOk();

        $this->assertSame(1, $app->apiKeys()->where('name', AppApiKey::PLAYGROUND_NAME)->count());
        $this->assertSame(2, UsageLog::query()->count());
        $this->assertLessThan(Money::fromUsd('5'), $app->refresh()->balance);
    }

    public function test_panel_chat_requires_balance_and_ownership(): void
    {
        $owner = User::factory()->inOrganization()->create();
        $app = $owner->currentOrganization->apps()->create(['name' => 'App']);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/apps/{$app->id}/chat/completions", ['model' => 'gpt-test', 'messages' => []])->assertStatus(402);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/apps/{$app->id}/chat/completions", ['model' => 'gpt-test', 'messages' => []])->assertNotFound();
    }
}
