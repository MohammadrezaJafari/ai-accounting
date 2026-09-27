<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Orders stay pending until an admin confirms the payment (bank transfer, invoice, ...).
 */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function initiate(Order $order): void
    {
        $order->update(['meta' => array_merge($order->meta ?? [], [
            'instructions' => 'Your order is awaiting confirmation by an administrator.',
        ])]);
    }
}
