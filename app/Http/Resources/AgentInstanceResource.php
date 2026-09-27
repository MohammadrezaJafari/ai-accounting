<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent->id,
                'slug' => $this->agent->slug,
                'name' => $this->agent->name,
                'unit_name' => $this->agent->unit_name,
            ]),
            'app' => $this->whenLoaded('app', fn () => ['id' => $this->app->id, 'name' => $this->app->name]),
            'config' => $this->config,
            'run_hours' => $this->run_hours ?? [],
            'run_days' => $this->run_days ?? [],
            'destinations' => AgentDestinationResource::collection($this->whenLoaded('destinations')),
            'is_active' => $this->is_active,
            'last_run_at' => $this->last_run_at,
            'next_run_at' => $this->next_run_at,
            'latest_run' => new AgentRunResource($this->whenLoaded('latestRun')),
            'created_at' => $this->created_at,
        ];
    }
}
