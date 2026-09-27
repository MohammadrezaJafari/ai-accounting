<?php

namespace App\Services\Gateway;

use App\Support\TokenUsage;

class OpenAiStreamCollector implements StreamCollector
{
    private ?array $usage = null;

    private string $text = '';

    public function onData(array $event): void
    {
        if (! empty($event['usage']) && is_array($event['usage'])) {
            $this->usage = $event['usage'];
        }

        foreach ($event['choices'] ?? [] as $choice) {
            $delta = $choice['delta'] ?? [];
            $this->text .= is_string($delta['content'] ?? null) ? $delta['content'] : '';

            foreach ($delta['tool_calls'] ?? [] as $call) {
                $this->text .= $call['function']['arguments'] ?? '';
            }
        }
    }

    public function usage(): ?TokenUsage
    {
        return $this->usage ? TokenUsage::fromOpenAi($this->usage) : null;
    }

    public function text(): string
    {
        return $this->text;
    }
}
