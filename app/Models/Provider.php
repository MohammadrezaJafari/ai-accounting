<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'base_url', 'native_format', 'native_base_url', 'markup_bps', 'is_active'])]
class Provider extends Model
{
    public const NATIVE_ANTHROPIC = 'anthropic';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'markup_bps' => 'integer',
        ];
    }

    public function keys(): HasMany
    {
        return $this->hasMany(ProviderKey::class);
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }
}
