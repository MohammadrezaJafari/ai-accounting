<?php

namespace App\Services\Agents;

/**
 * Outcome of one run. A null report means nothing new: the run uses no unit.
 */
final readonly class AgentResult
{
    /**
     * @param  array<string, mixed>  $meta  shown with the run (e.g. sources checked)
     * @param  array<string, mixed>  $state  kept on the instance for the next run
     */
    public function __construct(
        public ?string $report,
        public int $itemsFound = 0,
        public array $meta = [],
        public array $state = [],
    ) {}
}
