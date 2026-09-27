<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's remaining units of one agent and what they were paid for.
 */
#[Fillable(['organization_id', 'agent_id', 'units', 'value'])]
class AgentCredit extends Model
{
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Average paid price of one remaining unit (nano-USD).
     */
    public function unitValue(): int
    {
        return $this->units > 0 ? intdiv($this->value, $this->units) : 0;
    }
}
