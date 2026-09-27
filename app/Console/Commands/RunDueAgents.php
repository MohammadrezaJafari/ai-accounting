<?php

namespace App\Console\Commands;

use App\Models\AgentInstance;
use App\Models\AgentRun;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\AgentSchedule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agents:run-due')]
#[Description('Run the agent instances whose scheduled hour has come and fail runs past their deadline')]
class RunDueAgents extends Command
{
    public function handle(AgentRunner $runner): int
    {
        if ($expired = $runner->expireOverdue()) {
            $this->line("{$expired} overdue run(s) failed");
        }

        AgentInstance::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->whereHas('agent', fn ($query) => $query->where('is_active', true))
            ->each(function (AgentInstance $instance) use ($runner) {
                // Move the schedule first so a slow run is never picked up twice.
                $instance->update(['next_run_at' => AgentSchedule::next($instance->run_hours ?? [], $instance->run_days ?? [])]);

                if ($instance->hasRunInProgress()) {
                    return;
                }

                $run = $runner->execute($runner->start($instance, AgentRun::TRIGGER_SCHEDULE));
                $this->line("{$instance->name}: {$run->status}");
            });

        return self::SUCCESS;
    }
}
