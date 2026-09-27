<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bundle of agent units sold for `price` (nano-USD), paid from an app wallet.
 */
#[Fillable(['agent_id', 'name', 'units', 'price', 'is_active', 'sort_order'])]
class AgentPackage extends Model
{
    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'price' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
