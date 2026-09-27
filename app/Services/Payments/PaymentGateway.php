<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Implement this for a real payment provider (Stripe, Zarinpal, crypto, ...).
 * `initiate` may store a redirect URL in the order meta (`payment_url`) and
 * the provider callback should end in OrderService::markPaid().
 */
interface PaymentGateway
{
    public function name(): string;

    public function initiate(Order $order): void;
}
