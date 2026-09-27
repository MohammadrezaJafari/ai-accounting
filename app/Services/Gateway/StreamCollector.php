<?php

namespace App\Services\Gateway;

use App\Support\TokenUsage;

/**
 * Watches decoded SSE events of a streamed response to extract the final usage
 * and the generated text (used for an estimate if usage never arrives).
 */
interface StreamCollector
{
    public function onData(array $event): void;

    /** Null when the stream ended before the provider reported usage. */
    public function usage(): ?TokenUsage;

    public function text(): string;
}
