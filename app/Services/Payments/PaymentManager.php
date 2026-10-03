<?php

namespace App\Services\Payments;

use InvalidArgumentException;

class PaymentManager
{
    private const GATEWAYS = [
        'manual' => ManualGateway::class,
        'fake' => FakeGateway::class,
        'zarinpal' => ZarinpalGateway::class,
    ];

    public function gateway(?string $name = null): PaymentGateway
    {
        $name ??= config('billing.payment_gateway');

        $class = self::GATEWAYS[$name] ?? throw new InvalidArgumentException("Unknown payment gateway [$name].");

        return app($class);
    }
}
