<?php

namespace App\Http\Resources;

use App\Services\Agents\ConfigSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A configured agent. Secret parameters are never sent back, only whether they are set.
 */
class AgentInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $schema = ConfigSchema::for($this->agent);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent->id,
                'slug' => $this->agent->slug,
                'name' => $this->agent->name,
                'icon' => $this->agent->icon ?: 'smart_toy',
                'unit_name' => $this->agent->unit_name,
            ]),
            'app' => $this->whenLoaded('app', fn () => ['id' => $this->app->id, 'name' => $this->app->name]),
            'config' => (object) $schema->masked($this->config ?? []),
            'secrets_set' => $schema->secretsSet($this->config ?? []),
            'run_hours' => $this->run_hours ?? [],
            'run_days' => $this->run_days ?? [],
            'notify_empty' => $this->notify_empty,
            'destinations' => AgentDestinationResource::collection($this->whenLoaded('destinations')),
            'is_active' => $this->is_active,
            'last_run_at' => $this->last_run_at,
            'next_run_at' => $this->next_run_at,
            'latest_run' => new AgentRunResource($this->whenLoaded('latestRun')),
            'created_at' => $this->created_at,
        ];
    }
}
