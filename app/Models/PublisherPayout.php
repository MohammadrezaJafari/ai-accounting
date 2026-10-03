<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The publisher ledger (nano-USD): payouts the platform made against the earned share, and
 * deposits the publisher made from one of its apps' wallets to cover a negative balance.
 */
#[Fillable(['organization_id', 'type', 'app_id', 'amount', 'reference', 'note', 'paid_at', 'created_by'])]
class PublisherPayout extends Model
{
    public const TYPE_PAYOUT = 'payout';

    public const TYPE_DEPOSIT = 'deposit';

    protected $attributes = [
        'type' => self::TYPE_PAYOUT,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function isDeposit(): bool
    {
        return $this->type === self::TYPE_DEPOSIT;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
