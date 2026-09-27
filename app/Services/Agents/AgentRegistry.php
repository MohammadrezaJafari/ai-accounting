<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Support\AgentDriver;

/**
 * Picks the handler of an agent: its HTTP service, or a built-in implementation by slug.
 */
class AgentRegistry
{
    /** @var array<string, class-string<AgentHandler>> */
    private const BUILTIN = [
        Agent::NEWS_MONITOR => NewsMonitorAgent::class,
    ];

    public function handler(Agent $agent): AgentHandler
    {
        if ($agent->driver === AgentDriver::Http) {
            return app(HttpAgent::class);
        }

        $class = self::BUILTIN[$agent->slug] ?? throw new AgentException("ایجنت «{$agent->name}» پیاده‌سازی نشده است.");

        return app($class);
    }

    /**
     * @return list<string>
     */
    public static function builtinSlugs(): array
    {
        return array_keys(self::BUILTIN);
    }
}
