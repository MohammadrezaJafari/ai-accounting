<?php

namespace App\Services\Agents;

use App\Models\Agent;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The parameters a customer fills in for an agent, defined by the admin (or the publisher's
 * manifest) instead of in code. The same definition drives the customer's form, validation
 * and the `config` sent to the agent with every run.
 *
 * A field: key, label, type (see TYPES), required, section, hint, placeholder, default,
 * options (select / multiselect), min / max (number), max_items (lists).
 * Secret fields are sent to the agent but never shown again to the customer.
 */
class ConfigSchema
{
    public const TYPES = [
        'text' => 'متن کوتاه',
        'textarea' => 'متن بلند',
        'number' => 'عدد',
        'select' => 'انتخاب یکی',
        'multiselect' => 'انتخاب چندتا',
        'tags' => 'فهرست کلمه‌ها',
        'url' => 'آدرس وب',
        'url_list' => 'فهرست آدرس‌ها',
        'toggle' => 'روشن / خاموش',
        'secret' => 'کلید محرمانه',
    ];

    private const LIST_TYPES = ['multiselect', 'tags', 'url_list'];

    /**
     * @param  list<array<string, mixed>>  $fields  definitions already passed through define()
     */
    public function __construct(private array $fields) {}

    public static function for(Agent $agent): self
    {
        return new self(self::define($agent->config_schema ?? []));
    }

    /**
     * Check and tidy raw field definitions (from the admin form or a manifest).
     *
     * @param  array<int|string, mixed>  $raw
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException with a message for the admin
     */
    public static function define(array $raw): array
    {
        $fields = [];

        foreach (array_values($raw) as $index => $field) {
            $position = $index + 1;
            $key = is_array($field) ? (string) ($field['key'] ?? '') : '';
            $type = is_array($field) ? (string) ($field['type'] ?? 'text') : '';

            if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                throw new InvalidArgumentException("شناسهٔ پارامتر {$position} باید با حرف کوچک انگلیسی شروع شود و فقط حرف، عدد و _ داشته باشد.");
            }

            if (isset($fields[$key])) {
                throw new InvalidArgumentException("شناسهٔ «{$key}» تکراری است.");
            }

            if (! array_key_exists($type, self::TYPES)) {
                throw new InvalidArgumentException("نوع پارامتر «{$key}» معتبر نیست.");
            }

            $options = collect($field['options'] ?? [])
                ->map(fn ($option) => is_array($option)
                    ? ['value' => (string) ($option['value'] ?? ''), 'label' => (string) ($option['label'] ?? $option['value'] ?? '')]
                    : ['value' => (string) $option, 'label' => (string) $option])
                ->filter(fn (array $option) => $option['value'] !== '')
                ->unique('value')
                ->values()
                ->all();

            if (in_array($type, ['select', 'multiselect'], true) && $options === []) {
                throw new InvalidArgumentException("پارامتر «{$key}» گزینه ندارد.");
            }

            $definition = [
                'key' => $key,
                'label' => trim((string) ($field['label'] ?? '')) ?: $key,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'section' => self::text($field['section'] ?? null),
                'hint' => self::text($field['hint'] ?? null),
                'placeholder' => self::text($field['placeholder'] ?? null),
                'options' => $options,
                'min' => is_numeric($field['min'] ?? null) ? $field['min'] + 0 : null,
                'max' => is_numeric($field['max'] ?? null) ? $field['max'] + 0 : null,
                'max_items' => is_numeric($field['max_items'] ?? null) ? max(1, (int) $field['max_items']) : null,
            ];
            $definition['default'] = $type === 'secret' ? null : self::cast($definition, $field['default'] ?? null);

            $fields[$key] = $definition;
        }

        return array_values($fields);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Validation rules for a request's `config`.
     *
     * @param  array<string, mixed>  $previous  saved config, so a stored secret may be left blank
     * @return array<string, mixed>
     */
    public function rules(array $previous = []): array
    {
        $rules = ['config' => ['array']];

        foreach ($this->fields as $field) {
            $name = "config.{$field['key']}";
            $required = $field['required'] && ! ($field['type'] === 'secret' && filled($previous[$field['key']] ?? null));
            $presence = $required && $field['type'] !== 'toggle' ? 'required' : 'nullable';
            $maxItems = $field['max_items'] ?? ($field['type'] === 'url_list' ? 10 : 30);

            $rules[$name] = match ($field['type']) {
                'text', 'secret' => [$presence, 'string', 'max:'.(int) ($field['max'] ?? 500)],
                'textarea' => [$presence, 'string', 'max:'.(int) ($field['max'] ?? 4000)],
                'number' => array_values(array_filter([$presence, 'numeric',
                    $field['min'] !== null ? "min:{$field['min']}" : null,
                    $field['max'] !== null ? "max:{$field['max']}" : null])),
                'select' => [$presence, 'string', Rule::in(array_column($field['options'], 'value'))],
                'url' => [$presence, 'url:http,https', 'max:500'],
                'toggle' => ['nullable', 'boolean'],
                default => [$presence, 'array', ...($required ? ['min:1'] : []), "max:{$maxItems}"],
            };

            if (in_array($field['type'], self::LIST_TYPES, true)) {
                $rules["{$name}.*"] = match ($field['type']) {
                    'multiselect' => ['string', Rule::in(array_column($field['options'], 'value'))],
                    'url_list' => ['string', 'url:http,https', 'max:500'],
                    default => ['string', 'max:100'],
                };
            }
        }

        return $rules;
    }

    /**
     * Only the defined keys, cast to their types, with defaults for what was left out.
     * A blank secret keeps the saved one.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    public function normalize(array $input, array $previous = []): array
    {
        $config = [];

        foreach ($this->fields as $field) {
            $key = $field['key'];
            $value = self::cast($field, $input[$key] ?? null);

            if ($field['type'] === 'secret' && $value === null) {
                $value = $previous[$key] ?? null;
            }

            $config[$key] = $value ?? $field['default'];
        }

        return $config;
    }

    /**
     * The config as the customer may see it again: secrets blanked.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function masked(array $config): array
    {
        foreach ($this->secretKeys() as $key) {
            if (array_key_exists($key, $config)) {
                $config[$key] = null;
            }
        }

        return $config;
    }

    /**
     * Secret keys that have a saved value.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function secretsSet(array $config): array
    {
        return array_values(array_filter($this->secretKeys(), fn (string $key) => filled($config[$key] ?? null)));
    }

    /**
     * @return list<string>
     */
    private function secretKeys(): array
    {
        return array_column(array_filter($this->fields, fn (array $field) => $field['type'] === 'secret'), 'key');
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function cast(array $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($field['type']) {
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? $value + 0 : null,
            'multiselect', 'tags', 'url_list' => array_values(array_unique(array_filter(array_map(
                fn ($item) => trim((string) $item),
                is_array($value) ? $value : preg_split('/[\n,،]+/u', (string) $value),
            ), fn (string $item) => $item !== ''))) ?: null,
            default => is_scalar($value) ? (trim((string) $value) ?: null) : null,
        };
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
