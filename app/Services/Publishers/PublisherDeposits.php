<?php

namespace App\Services\Publishers;

use App\Models\App;
use App\Models\Organization;
use App\Models\PublisherPayout;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A publisher covering its debt (model costs above its share) from one of its own apps'
 * wallets: the wallet is debited and the publisher ledger credited in one transaction.
 */
class PublisherDeposits
{
    public function __construct(private WalletService $wallets) {}

    /**
     * @param  int  $amount  nano-USD
     */
    public function deposit(Organization $publisher, App $app, int $amount, User $by): PublisherPayout
    {
        if ($app->organization_id !== $publisher->id) {
            throw ValidationException::withMessages(['app_id' => 'این اپ متعلق به سازمان شما نیست.']);
        }

        return DB::transaction(function () use ($publisher, $app, $amount, $by) {
            $locked = App::query()->lockForUpdate()->findOrFail($app->id);

            if ($locked->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'موجودی کیف پول این اپ کافی نیست. موجودی: '.Money::format($locked->balance),
                ]);
            }

            $this->wallets->adjust($locked, -$amount, WalletTransaction::TYPE_PUBLISHER_DEPOSIT, 'جبران بدهی ناشر', by: $by);

            return $publisher->payouts()->create([
                'type' => PublisherPayout::TYPE_DEPOSIT,
                'app_id' => $app->id,
                'amount' => $amount,
                'note' => "از کیف پول اپ {$app->name}",
                'paid_at' => now(),
                'created_by' => $by->id,
            ]);
        });
    }
}
