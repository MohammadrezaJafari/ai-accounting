<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One gateway request. `cost` is what the provider charges us, `charge` what the app paid;
 * the difference is platform profit.
 */
#[Fillable([
    'request_id', 'app_id', 'app_api_key_id', 'agent_run_id', 'ai_model_id', 'provider_id', 'endpoint', 'model', 'stream',
    'input_tokens', 'cached_input_tokens', 'cache_write_tokens', 'output_tokens',
    'cost', 'charge', 'status_code', 'latency_ms', 'error', 'ip',
])]
class UsageLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'stream' => 'boolean',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'integer',
            'charge' => 'integer',
            'status_code' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(AppApiKey::class, 'app_api_key_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
