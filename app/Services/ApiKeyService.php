<?php

namespace App\Services;

use App\Models\App;
use App\Models\AppApiKey;
use Illuminate\Support\Str;

class ApiKeyService
{
    /**
     * @return array{0: AppApiKey, 1: string} the key model and its plaintext (shown once)
     */
    public function create(App $app, array $attributes): array
    {
        $plain = config('billing.api_key_prefix').Str::random(48);

        $key = $app->apiKeys()->create($attributes + [
            'key_prefix' => substr($plain, 0, 14),
            'key_hash' => AppApiKey::hash($plain),
        ]);

        return [$key, $plain];
    }

    public function findActive(string $plain): ?AppApiKey
    {
        return AppApiKey::query()->with('app')->where('key_hash', AppApiKey::hash($plain))->first();
    }
}
