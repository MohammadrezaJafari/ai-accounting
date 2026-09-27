<?php

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\AiModel;
use App\Models\UsageLog;
use App\Services\Gateway\GatewayException;
use App\Services\Gateway\UpstreamKeyPicker;
use App\Services\PricingService;
use App\Support\TokenUsage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Model calls made by an agent run. They go straight to the provider with our key and are
 * logged at cost with charge 0: the customer pays per unit, not per token. A run stops
 * once its calls reach the agent's `max_cost_per_run`.
 */
class AgentLlm
{
    public function __construct(private UpstreamKeyPicker $keys, private PricingService $pricing) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function complete(AgentRun $run, array $messages): string
    {
        $agent = $run->agent;
        $spent = (int) $run->usageLogs()->sum('cost');

        if ($agent->max_cost_per_run !== null && $spent >= $agent->max_cost_per_run) {
            throw new AgentException('هزینهٔ این اجرا به سقف مجاز رسید و متوقف شد.');
        }

        $model = AiModel::query()->available()->with('provider')->where('public_id', $agent->model)->first()
            ?? throw new AgentException('مدل این ایجنت در دسترس نیست.');

        try {
            $providerKey = $this->keys->pick($model->provider);
        } catch (GatewayException) {
            throw new AgentException('فعلاً امکان اتصال به مدل وجود ندارد.');
        }

        $startedAt = hrtime(true);
        $status = 0;
        $usage = new TokenUsage;
        $error = null;

        try {
            $response = Http::acceptJson()
                ->withToken($providerKey->api_key)
                ->timeout(config('billing.upstream_timeout'))
                ->post(rtrim($model->provider->base_url, '/').'/chat/completions', [
                    'model' => $model->upstream_id,
                    'messages' => $messages,
                ]);

            $status = $response->status();
            $usage = TokenUsage::fromOpenAi($response->json('usage'));
            $content = (string) $response->json('choices.0.message.content', '');
            $error = $response->successful() ? null : Str::limit((string) $response->json('error.message', $response->body()), 480);
        } catch (Throwable $e) {
            $status = 502;
            $content = '';
            $error = Str::limit($e->getMessage(), 480);
        }

        $this->keys->reportResult($providerKey, $status);

        UsageLog::query()->create([
            'request_id' => (string) Str::uuid(),
            'app_id' => $run->instance->app_id,
            'agent_run_id' => $run->id,
            'ai_model_id' => $model->id,
            'provider_id' => $model->provider_id,
            'endpoint' => 'agent',
            'model' => $model->public_id,
            'stream' => false,
            'input_tokens' => $usage->input,
            'cached_input_tokens' => $usage->cachedInput,
            'cache_write_tokens' => $usage->cacheWrite,
            'output_tokens' => $usage->output,
            'cost' => $usage->isEmpty() ? 0 : $this->pricing->calculate($model, null, $usage)['cost'],
            'charge' => 0,
            'status_code' => $status,
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'error' => $error,
        ]);

        if ($error !== null || trim($content) === '') {
            throw new AgentException('مدل پاسخی نداد. دوباره تلاش کنید.');
        }

        return $content;
    }
}
