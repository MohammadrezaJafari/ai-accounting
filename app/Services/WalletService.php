<?php

namespace App\Services;

use App\Models\App;
use App\Models\AppApiKey;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Change an app balance and record it in the ledger. Negative amounts debit.
     */
    public function adjust(App $app, int $amount, string $type, ?string $description = null, ?Order $order = null, ?User $by = null): WalletTransaction
    {
        return DB::transaction(function () use ($app, $amount, $type, $description, $order, $by) {
            $locked = App::query()->lockForUpdate()->findOrFail($app->id);
            $locked->balance += $amount;
            $locked->save();
            $app->balance = $locked->balance;

            return WalletTransaction::query()->create([
                'app_id' => $app->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $locked->balance,
                'description' => $description,
                'order_id' => $order?->id,
                'created_by' => $by?->id,
            ]);
        });
    }

    /**
     * Deduct a request's charge. Uses atomic updates so concurrent requests never lose a debit;
     * the balance may dip slightly below zero on the last request before it runs out.
     */
    public function chargeUsage(App $app, ?AppApiKey $key, int $charge): void
    {
        if ($charge <= 0) {
            return;
        }

        App::query()->whereKey($app->id)->decrement('balance', $charge);
        $key && AppApiKey::query()->whereKey($key->id)->increment('spent', $charge);
    }
}
