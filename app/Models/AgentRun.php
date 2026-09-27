<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One execution of an agent instance. `revenue` is the paid value of the units it used and
 * `cost` what its model calls cost us (both nano-USD); `publisher_share` is the part of the
 * revenue owed to the agent's publisher.
 * An HTTP agent gets a token for the run (stored hashed) to call our models and post its
 * result until `deadline_at`; `data` is the structured output it returned, if any.
 */
#[Fillable(['agent_instance_id', 'agent_id', 'organization_id', 'status', 'trigger', 'units', 'revenue', 'publisher_share', 'cost', 'items_found', 'report', 'data', 'error', 'meta', 'started_at', 'deadline_at', 'finished_at'])]
#[Hidden(['token_hash'])]
class AgentRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    /** Delivered a unit (e.g. a report). */
    public const STATUS_SUCCEEDED = 'succeeded';

    /** Nothing new to report; no unit used. */
    public const STATUS_EMPTY = 'empty';

    /** Not run because the organization has no units left. */
    public const STATUS_NO_CREDITS = 'no_credits';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    /** A publisher trying its own agent: no credits, no delivery. */
    public const TRIGGER_TEST = 'test';

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'revenue' => 'integer',
            'publisher_share' => 'integer',
            'cost' => 'integer',
            'items_found' => 'integer',
            'meta' => 'array',
            'data' => 'array',
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(AgentInstance::class, 'agent_instance_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    /**
     * A new token for the agent to act on this run until the deadline; only its hash is kept.
     */
    public function issueToken(int $minutes): string
    {
        $token = 'agr_'.Str::random(48);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'deadline_at' => now()->addMinutes($minutes)])->save();

        return $token;
    }

    /**
     * The running run a token belongs to, while it is still valid.
     */
    public static function findByToken(string $token): ?self
    {
        return self::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('status', self::STATUS_RUNNING)
            ->where('deadline_at', '>', now())
            ->first();
    }

    public function isTest(): bool
    {
        return $this->trigger === self::TRIGGER_TEST;
    }

    public function margin(): int
    {
        return $this->revenue - $this->publisher_share - $this->cost;
    }
}
