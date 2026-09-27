<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Outcome of one run. A null report means nothing new: the run uses no unit.
 * A pending result means an HTTP agent accepted the run and will post its result later.
 */
final readonly class AgentResult
{
    /**
     * @param  array<string, mixed>  $meta  shown with the run: `notes` (lines for the customer) and `errors` (warnings)
     * @param  array<string, mixed>  $state  kept on the instance for the next run
     * @param  ?int  $units  units the output is worth (default 1), capped by the agent's `max_units_per_run`
     * @param  array<string, mixed>  $data  structured output, forwarded to webhooks
     */
    public function __construct(
        public ?string $report,
        public int $itemsFound = 0,
        public array $meta = [],
        public array $state = [],
        public ?int $units = null,
        public array $data = [],
        public bool $pending = false,
    ) {}

    public static function pending(): self
    {
        return new self(null, pending: true);
    }

    /**
     * A result sent by an HTTP agent (in its response or its callback).
     *
     * `status`: succeeded | empty | failed; `report`: Markdown; `units`, `items`,
     * `notes` (list of lines), `warnings`, `state`, `data`, `error`.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws AgentException when the payload is invalid or the agent reports a failure
     */
    public static function fromPayload(array $payload, array $previousState = []): self
    {
        $validator = Validator::make($payload, [
            'status' => ['required', 'in:succeeded,empty,failed'],
            'report' => ['required_if:status,succeeded', 'nullable', 'string', 'max:100000'],
            'units' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'array', 'max:10'],
            'notes.*' => ['string', 'max:300'],
            'warnings' => ['nullable', 'array', 'max:10'],
            'warnings.*' => ['string', 'max:300'],
            'state' => ['nullable', 'array'],
            'data' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            throw new AgentException('پاسخ ایجنت معتبر نبود: '.$validator->errors()->first());
        }

        foreach (['state', 'data'] as $key) {
            if (strlen((string) json_encode($payload[$key] ?? [])) > 262_144) {
                throw new AgentException("بخش {$key} در پاسخ ایجنت بیش از ۲۵۶ کیلوبایت است.");
            }
        }

        if ($payload['status'] === 'failed') {
            throw new AgentException(Str::limit(trim((string) ($payload['error'] ?? '')) ?: 'ایجنت نتوانست کار را انجام دهد.', 480));
        }

        $report = $payload['status'] === 'succeeded' ? trim((string) $payload['report']) : null;

        return new self(
            report: $report === '' ? null : $report,
            itemsFound: (int) ($payload['items'] ?? 0),
            meta: array_filter(['notes' => $payload['notes'] ?? [], 'errors' => $payload['warnings'] ?? []]),
            state: $payload['state'] ?? $previousState,
            units: isset($payload['units']) ? (int) $payload['units'] : null,
            data: $payload['data'] ?? [],
        );
    }
}
