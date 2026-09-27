<?php

namespace App\Support;

/**
 * Where an agent's work happens. Marketplace agents are HTTP services run by us or by a
 * third-party publisher; built-in agents are implemented in this codebase.
 */
enum AgentDriver: string
{
    case Http = 'http';
    case Builtin = 'builtin';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'سرویس خارجی (HTTP)',
            self::Builtin => 'داخلی',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $driver) => [$driver->value => $driver->label()])->all();
    }
}
