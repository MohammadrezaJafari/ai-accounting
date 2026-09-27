<?php

namespace App\Services\Gateway;

/**
 * Incrementally parses a Server-Sent Events byte stream and hands each decoded
 * `data:` JSON payload to a callback. Chunks may split lines at any byte.
 */
final class SseParser
{
    private string $buffer = '';

    /** @param callable(array): void $onData */
    public function __construct(private $onData) {}

    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = substr($this->buffer, $pos + 1);
            $this->line($line);
        }
    }

    public function finish(): void
    {
        if ($this->buffer !== '') {
            $this->line(rtrim($this->buffer, "\r"));
            $this->buffer = '';
        }
    }

    private function line(string $line): void
    {
        if (! str_starts_with($line, 'data:')) {
            return;
        }

        $payload = ltrim(substr($line, 5));
        if ($payload === '' || $payload === '[DONE]') {
            return;
        }

        $data = json_decode($payload, true);
        if (is_array($data)) {
            ($this->onData)($data);
        }
    }
}
