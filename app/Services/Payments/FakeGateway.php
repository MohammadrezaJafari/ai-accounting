<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\OrderService;

/**
 * Development only: every order is paid immediately.
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function initiate(Order $order): void
    {
        app(OrderService::class)->markPaid($order, 'fake-'.$order->id);
    }
}
