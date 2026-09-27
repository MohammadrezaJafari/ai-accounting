<?php

namespace App\Services\Agents;

use App\Models\Agent;

/**
 * Maps an agent product (by slug) to its implementation.
 */
class AgentRegistry
{
    /** @var array<string, class-string<AgentHandler>> */
    private const HANDLERS = [
        Agent::NEWS_MONITOR => NewsMonitorAgent::class,
    ];

    public function handler(Agent $agent): AgentHandler
    {
        $class = self::HANDLERS[$agent->slug] ?? throw new AgentException("ایجنت «{$agent->name}» پیاده‌سازی نشده است.");

        return app($class);
    }
}
