<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real upstream API key owned by the platform (e.g. an OpenAI key).
 */
#[Fillable(['provider_id', 'name', 'api_key', 'priority', 'is_active'])]
#[Hidden(['api_key'])]
class ProviderKey extends Model
{
    protected $appends = ['masked_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'failure_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function getMaskedKeyAttribute(): string
    {
        $key = (string) $this->api_key;

        return strlen($key) <= 10 ? str_repeat('*', strlen($key)) : substr($key, 0, 6).'…'.substr($key, -4);
    }
}
