<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-editable billing settings, falling back to config/billing.php.
 * Money values are exposed in nano-USD.
 */
class SettingsService
{
    private const CACHE_KEY = 'billing.settings';

    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());

        return array_merge($this->defaults(), array_intersect_key($stored, $this->defaults()));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key];
    }

    public function update(array $values): array
    {
        foreach (array_intersect_key($values, $this->defaults()) as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);

        return $this->all();
    }

    public function defaults(): array
    {
        return [
            'default_markup_bps' => (int) config('billing.default_markup_bps'),
            'min_balance' => Money::fromUsd(config('billing.min_balance_usd')),
            'custom_topup_enabled' => (bool) config('billing.custom_topup.enabled'),
            'custom_topup_min' => Money::fromUsd(config('billing.custom_topup.min_usd')),
            'custom_topup_max' => Money::fromUsd(config('billing.custom_topup.max_usd')),
        ];
    }
}
