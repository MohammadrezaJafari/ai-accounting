<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger of balance changes other than per-request usage (which lives in usage_logs).
 */
#[Fillable(['app_id', 'type', 'amount', 'balance_after', 'description', 'order_id', 'created_by'])]
class WalletTransaction extends Model
{
    public const TYPE_TOPUP = 'topup';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_REFUND = 'refund';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
