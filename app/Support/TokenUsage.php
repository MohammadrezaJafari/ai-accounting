<?php

namespace App\Support;

/**
 * Normalised token counts. `input` never includes cached or cache-write tokens.
 */
final class TokenUsage
{
    public function __construct(
        public int $input = 0,
        public int $cachedInput = 0,
        public int $cacheWrite = 0,
        public int $output = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->input + $this->cachedInput + $this->cacheWrite + $this->output === 0;
    }

    /**
     * OpenAI-style `usage` object: prompt_tokens includes cached tokens.
     */
    public static function fromOpenAi(?array $usage): self
    {
        if (! $usage) {
            return new self;
        }

        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        return new self(
            input: max(0, $prompt - $cached),
            cachedInput: $cached,
            output: (int) ($usage['completion_tokens'] ?? 0),
        );
    }

    /**
     * Anthropic-style `usage` object: input_tokens excludes cache reads/writes.
     */
    public static function fromAnthropic(?array $usage): self
    {
        if (! $usage) {
            return new self;
        }

        return new self(
            input: (int) ($usage['input_tokens'] ?? 0),
            cachedInput: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheWrite: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            output: (int) ($usage['output_tokens'] ?? 0),
        );
    }
}
