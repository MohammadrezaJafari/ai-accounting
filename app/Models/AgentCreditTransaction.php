<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger of agent units: purchases (+), used units (−) and admin adjustments.
 */
#[Fillable(['organization_id', 'agent_id', 'app_id', 'agent_run_id', 'agent_package_id', 'type', 'units', 'value', 'description', 'created_by'])]
class AgentCreditTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_USAGE = 'usage';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'value' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
