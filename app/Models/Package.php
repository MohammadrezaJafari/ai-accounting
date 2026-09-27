<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A prepaid bundle: the customer pays `price` and receives `credit` (both nano-USD),
 * so credit > price means a bonus.
 */
#[Fillable(['name', 'description', 'price', 'credit', 'is_active', 'sort_order'])]
class Package extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'credit' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
