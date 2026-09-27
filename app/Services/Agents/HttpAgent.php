<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * An agent that is its own service: we POST a signed run request to its `endpoint_url` and it
 * answers with the result (200) or accepts the run (202) and posts the result to
 * `platform.result_url` before the deadline. For model calls it uses our OpenAI-compatible
 * endpoint at `platform.llm_base_url` with the run token, so the cost lands on the run.
 *
 * Requests carry `X-Agent-Timestamp` and `X-Agent-Signature: sha256=HMAC(timestamp + "." + body)`
 * with the agent's signing secret; agents should reject unsigned or stale requests.
 */
class HttpAgent implements AgentHandler
{
    public function run(AgentRun $run, AgentLlm $llm): AgentResult
    {
        $agent = $run->agent;
        $instance = $run->instance;
        $token = $run->issueToken($agent->run_deadline_minutes);

        $response = $this->send($agent, [
            'event' => 'run',
            'run' => [
                'id' => $run->id,
                'trigger' => $run->trigger,
                'deadline' => $run->deadline_at->toIso8601String(),
            ],
            'agent' => ['slug' => $agent->slug, 'unit_name' => $agent->unit_name, 'max_units' => $agent->max_units_per_run],
            'instance' => ['id' => $instance->id, 'name' => $instance->name],
            'organization' => ['id' => $run->organization_id, 'name' => $run->organization->name],
            'config' => (object) ($instance->config ?? []),
            'state' => (object) ($instance->state ?? []),
            'locale' => 'fa',
            'timezone' => config('billing.display_timezone'),
            'platform' => [
                'token' => $token,
                'result_url' => url("/agent-api/runs/{$run->id}/result"),
                'llm_base_url' => url('/agent-api/v1'),
                'default_model' => $agent->model,
            ],
        ]);

        if ($response->status() === 202) {
            return AgentResult::pending();
        }

        if (! $response->successful()) {
            throw new AgentException("سرویس ایجنت پاسخ {$response->status()} داد.");
        }

        if (! is_array($response->json())) {
            throw new AgentException('پاسخ سرویس ایجنت JSON نبود.');
        }

        return AgentResult::fromPayload($response->json(), $instance->state ?? []);
    }

    /**
     * Check that the service is up and accepts our signature; returns the error, if any.
     */
    public function ping(Agent $agent): ?string
    {
        try {
            $response = $this->send($agent, ['event' => 'ping'], 10);
        } catch (AgentException $e) {
            return $e->getMessage();
        }

        return $response->successful() ? null : "سرویس پاسخ {$response->status()} داد.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(Agent $agent, array $payload, ?int $timeout = null): Response
    {
        if (! $agent->endpoint_url) {
            throw new AgentException('آدرس سرویس این ایجنت تنظیم نشده است.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        try {
            return Http::timeout($timeout ?? $agent->timeout_seconds)
                ->acceptJson()
                ->withHeaders([
                    'X-Agent-Slug' => $agent->slug,
                    'X-Agent-Timestamp' => $timestamp,
                    'X-Agent-Signature' => 'sha256='.self::signature($timestamp, $body, (string) $agent->signing_secret),
                ])
                ->withBody($body, 'application/json')
                ->post($agent->endpoint_url);
        } catch (ConnectionException) {
            throw new AgentException('سرویس ایجنت در دسترس نبود یا در زمان مقرر پاسخ نداد.');
        }
    }

    public static function signature(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
