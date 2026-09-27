<?php

namespace App\Services\Gateway;

use App\Models\Provider;
use App\Models\ProviderKey;

/**
 * Picks an upstream key: highest priority first, spread randomly among keys of equal priority.
 */
class UpstreamKeyPicker
{
    public function pick(Provider $provider): ProviderKey
    {
        $keys = $provider->keys()->where('is_active', true)->get();

        if ($keys->isEmpty()) {
            throw new GatewayException(503, "No upstream key configured for provider [{$provider->slug}].", 'api_error');
        }

        $top = $keys->max('priority');

        return $keys->where('priority', $top)->random();
    }

    public function reportResult(ProviderKey $key, int $status): void
    {
        $key->timestamps = false;

        if ($status === 401 || $status === 403) {
            $key->failure_count++;
        } elseif ($status < 400) {
            $key->failure_count = 0;
        }

        $key->last_used_at = now();
        $key->save();
    }
}
