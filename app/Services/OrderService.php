<?php

namespace App\Services;

use App\Models\App;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private WalletService $wallet,
        private SettingsService $settings,
        private PaymentManager $payments,
    ) {}

    public function forPackage(User $user, App $app, Package $package): Order
    {
        if (! $package->is_active) {
            throw ValidationException::withMessages(['package_id' => 'This package is not available.']);
        }

        return $this->create($user, $app, [
            'package_id' => $package->id,
            'type' => Order::TYPE_PACKAGE,
            'amount' => $package->price,
            'credit' => $package->credit,
            'meta' => ['package_name' => $package->name],
        ]);
    }

    public function forCustomAmount(User $user, App $app, int $amount): Order
    {
        $s = $this->settings->all();

        if (! $s['custom_topup_enabled']) {
            throw ValidationException::withMessages(['amount' => 'Custom top-ups are disabled.']);
        }

        if ($amount < $s['custom_topup_min'] || $amount > $s['custom_topup_max']) {
            throw ValidationException::withMessages([
                'amount' => sprintf('Amount must be between $%s and $%s.', Money::toUsd($s['custom_topup_min']), Money::toUsd($s['custom_topup_max'])),
            ]);
        }

        return $this->create($user, $app, [
            'type' => Order::TYPE_CUSTOM,
            'amount' => $amount,
            'credit' => $amount,
        ]);
    }

    /**
     * Credit the app once. Safe to call repeatedly (e.g. from gateway callbacks).
     */
    public function markPaid(Order $order, ?string $gatewayRef = null, ?User $by = null): Order
    {
        return DB::transaction(function () use ($order, $gatewayRef, $by) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === Order::STATUS_PAID) {
                return $locked;
            }

            $locked->forceFill([
                'status' => Order::STATUS_PAID,
                'paid_at' => now(),
                'gateway_ref' => $gatewayRef ?? $locked->gateway_ref,
            ])->save();

            $this->wallet->adjust(
                $locked->app,
                $locked->credit,
                WalletTransaction::TYPE_TOPUP,
                $locked->type === Order::TYPE_PACKAGE ? 'Package: '.($locked->meta['package_name'] ?? '') : 'Custom top-up',
                $locked,
                $by,
            );

            return $locked->refresh();
        });
    }

    public function cancel(Order $order): Order
    {
        if ($order->status !== Order::STATUS_PENDING) {
            throw ValidationException::withMessages(['order' => 'Only pending orders can be cancelled.']);
        }

        $order->update(['status' => Order::STATUS_CANCELLED]);

        return $order;
    }

    private function create(User $user, App $app, array $attributes): Order
    {
        $gateway = $this->payments->gateway();

        $order = Order::query()->create($attributes + [
            'user_id' => $user->id,
            'app_id' => $app->id,
            'status' => Order::STATUS_PENDING,
            'gateway' => $gateway->name(),
        ]);

        $gateway->initiate($order);

        return $order->refresh();
    }
}
