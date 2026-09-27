<?php

namespace App\Services\Gateway;

use App\Support\TokenUsage;

/**
 * message_start carries input usage; message_delta carries the final (cumulative) output usage.
 */
class AnthropicStreamCollector implements StreamCollector
{
    private array $usage = [];

    private bool $complete = false;

    private string $text = '';

    public function onData(array $event): void
    {
        match ($event['type'] ?? null) {
            'message_start' => $this->usage = array_merge($this->usage, $event['message']['usage'] ?? []),
            'message_delta' => $this->delta($event),
            'content_block_delta' => $this->text .= $event['delta']['text'] ?? $event['delta']['partial_json'] ?? $event['delta']['thinking'] ?? '',
            default => null,
        };
    }

    public function usage(): ?TokenUsage
    {
        return $this->complete ? TokenUsage::fromAnthropic($this->usage) : null;
    }

    public function text(): string
    {
        return $this->text;
    }

    private function delta(array $event): void
    {
        $this->usage = array_merge($this->usage, array_filter($event['usage'] ?? [], fn ($v) => $v !== null));
        $this->complete = true;
    }
}
