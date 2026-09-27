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
 * Model calls made by an agent run, by a built-in agent or by an HTTP agent through
 * /agent-api/v1/chat/completions with its run token. They go to the provider with our key
 * and are logged at cost with charge 0: the customer pays per unit, not per token.
 * A run stops once its calls reach the agent's `max_cost_per_run`.
 */
class AgentLlm
{
    public function __construct(private UpstreamKeyPicker $keys, private PricingService $pricing) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function complete(AgentRun $run, array $messages): string
    {
        $model = $run->agent->model ?? throw new AgentException('مدل پیش‌فرض این ایجنت تنظیم نشده است.');
        ['status' => $status, 'body' => $body] = $this->chat($run, ['model' => $model, 'messages' => $messages]);
        $content = (string) data_get($body, 'choices.0.message.content', '');

        if ($status >= 400 || trim($content) === '') {
            throw new AgentException('مدل پاسخی نداد. دوباره تلاش کنید.');
        }

        return $content;
    }

    /**
     * An OpenAI-compatible chat completion for a run (non-streaming). `model` defaults to the
     * agent's model and must be one it is allowed to use.
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: array<string, mixed>}
     *
     * @throws AgentException when the run may not make the call
     */
    public function chat(AgentRun $run, array $body): array
    {
        $agent = $run->agent;
        $publicId = (string) ($body['model'] ?? $agent->model);
        $spent = (int) $run->usageLogs()->sum('cost');

        if ($agent->max_cost_per_run !== null && $spent >= $agent->max_cost_per_run) {
            throw new AgentException('هزینهٔ این اجرا به سقف مجاز رسید و متوقف شد.');
        }

        if ($publicId === '' || ! $agent->allowsModel($publicId)) {
            throw new AgentException("این ایجنت اجازهٔ استفاده از مدل «{$publicId}» را ندارد.");
        }

        $model = AiModel::query()->available()->with('provider')->where('public_id', $publicId)->first()
            ?? throw new AgentException("مدل «{$publicId}» در دسترس نیست.");

        try {
            $providerKey = $this->keys->pick($model->provider);
        } catch (GatewayException) {
            throw new AgentException('فعلاً امکان اتصال به مدل وجود ندارد.');
        }

        $startedAt = hrtime(true);
        $usage = new TokenUsage;
        $error = null;

        try {
            $response = Http::acceptJson()
                ->withToken($providerKey->api_key)
                ->timeout(config('billing.upstream_timeout'))
                ->post(rtrim($model->provider->base_url, '/').'/chat/completions', [
                    ...$body,
                    'model' => $model->upstream_id,
                    'stream' => false,
                ]);

            $status = $response->status();
            $json = is_array($response->json()) ? $response->json() : [];
            $usage = TokenUsage::fromOpenAi($json['usage'] ?? null);
            $error = $response->successful() ? null : Str::limit((string) data_get($json, 'error.message', $response->body()), 480);
        } catch (Throwable $e) {
            $status = 502;
            $json = ['error' => ['message' => 'The model provider could not be reached.', 'type' => 'api_error']];
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

        if (isset($json['model'])) {
            $json['model'] = $model->public_id;
        }

        return ['status' => $status, 'body' => $json];
    }
}
