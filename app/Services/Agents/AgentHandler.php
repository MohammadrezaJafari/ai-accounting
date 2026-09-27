<?php

namespace App\Services\Agents;

use App\Models\AgentRun;

/**
 * Does the work of an agent run: an HTTP service (HttpAgent) or a built-in implementation.
 * The customer's parameters are defined by the agent's `config_schema`, not by the handler.
 */
interface AgentHandler
{
    /**
     * Do the work once. Model calls go through `$llm` so they are costed against the run.
     */
    public function run(AgentRun $run, AgentLlm $llm): AgentResult;
}
