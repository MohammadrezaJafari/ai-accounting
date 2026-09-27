<?php

namespace App\Services\Agents;

use App\Models\AgentRun;

/**
 * The implementation behind an agent product.
 */
interface AgentHandler
{
    /**
     * Validation rules for an instance's `config` (keys are relative to `config.`).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Normalised config with defaults filled in.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function normalize(array $config): array;

    /**
     * Do the work once. Model calls go through `$llm` so they are costed against the run.
     */
    public function run(AgentRun $run, AgentLlm $llm): AgentResult;
}
