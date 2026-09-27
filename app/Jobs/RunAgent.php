<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\Agents\AgentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Executes a queued agent run (manual runs are dispatched after the response is sent).
 */
class RunAgent implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public AgentRun $run) {}

    public function handle(AgentRunner $runner): void
    {
        $runner->execute($this->run);
    }
}
