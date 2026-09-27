<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prices are nano-USD per 1M tokens. `*_price` is what the provider charges us;
 * `sell_*_price` optionally pins the customer price instead of using a markup.
 */
#[Fillable([
    'provider_id', 'name', 'description', 'public_id', 'upstream_id', 'context_window',
    'input_price', 'output_price', 'cached_input_price', 'cache_write_price',
    'markup_bps', 'sell_input_price', 'sell_output_price', 'sell_cached_input_price', 'sell_cache_write_price',
    'is_active', 'is_featured',
])]
class AiModel extends Model
{
    public const PRICE_FIELDS = [
        'input_price', 'output_price', 'cached_input_price', 'cache_write_price',
        'sell_input_price', 'sell_output_price', 'sell_cached_input_price', 'sell_cache_write_price',
    ];

    protected function casts(): array
    {
        return array_fill_keys(self::PRICE_FIELDS, 'integer') + [
            'markup_bps' => 'integer',
            'context_window' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('provider', fn (Builder $q) => $q->where('is_active', true));
    }
}
