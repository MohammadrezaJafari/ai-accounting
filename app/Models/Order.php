<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'app_id', 'package_id', 'type', 'amount', 'credit', 'status', 'gateway', 'gateway_ref', 'meta', 'paid_at'])]
class Order extends Model
{
    public const TYPE_PACKAGE = 'package';

    public const TYPE_CUSTOM = 'custom';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'credit' => 'integer',
            'meta' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
