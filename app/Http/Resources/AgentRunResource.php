<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run as the customer sees it: status, units used and the report — not our cost.
 */
class AgentRunResource extends JsonResource
{
    public bool $withReport = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_instance_id' => $this->agent_instance_id,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'input' => $this->input,
            'units' => $this->units,
            'items_found' => $this->items_found,
            'error' => $this->error,
            'meta' => $this->meta,
            'report' => $this->when($this->withReport, fn () => $this->report),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }

    public function withReport(): static
    {
        $this->withReport = true;

        return $this;
    }
}
