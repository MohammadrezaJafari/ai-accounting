<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\OrderService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Zarinpal (v4 REST API). Orders are priced in USD, so the amount is converted to Toman at the
 * configured rate when the payment starts; the callback verifies the same amount and marks the
 * order paid with Zarinpal's reference id.
 */
class ZarinpalGateway implements PaymentGateway
{
    public const SUCCESS = 100;

    public const ALREADY_VERIFIED = 101;

    public function __construct(private OrderService $orders) {}

    public function name(): string
    {
        return 'zarinpal';
    }

    public function initiate(Order $order): void
    {
        if (blank(config('services.zarinpal.merchant_id')) || ! is_numeric(config('services.zarinpal.toman_per_usd'))) {
            $this->fail($order, 'درگاه زرین‌پال هنوز تنظیم نشده است.');
        }

        $toman = $this->toman($order->amount);

        try {
            $response = $this->http()->post('/pg/v4/payment/request.json', [
                'merchant_id' => config('services.zarinpal.merchant_id'),
                'amount' => $toman,
                'currency' => 'IRT',
                'callback_url' => route('payments.zarinpal.callback', $order),
                'description' => "شارژ کیف پول — سفارش {$order->id}",
                'metadata' => array_filter(['email' => $order->user?->email, 'order_id' => (string) $order->id]),
            ]);
        } catch (ConnectionException) {
            $this->fail($order, 'اتصال به درگاه زرین‌پال برقرار نشد. کمی بعد دوباره تلاش کنید.');
        }

        $authority = $response->json('data.authority');

        if ((int) $response->json('data.code') !== self::SUCCESS || ! is_string($authority)) {
            $this->fail($order, 'درگاه زرین‌پال درخواست را نپذیرفت: '.$this->errorOf($response->json()));
        }

        $order->update(['meta' => array_merge($order->meta ?? [], [
            'authority' => $authority,
            'amount_toman' => $toman,
            'payment_url' => $this->baseUrl()."/pg/StartPay/{$authority}",
        ])]);
    }

    /**
     * Checks a returning payment with Zarinpal and settles the order.
     *
     * @return bool whether the order is paid
     */
    public function verify(Order $order, string $authority, bool $returnedOk): bool
    {
        if ($order->status === Order::STATUS_PAID) {
            return true;
        }

        if ($order->status !== Order::STATUS_PENDING || ($order->meta['authority'] ?? null) !== $authority) {
            return false;
        }

        if (! $returnedOk) {
            $order->update(['status' => Order::STATUS_FAILED]);

            return false;
        }

        try {
            $response = $this->http()->post('/pg/v4/payment/verify.json', [
                'merchant_id' => config('services.zarinpal.merchant_id'),
                'amount' => $order->meta['amount_toman'],
                'authority' => $authority,
            ]);
        } catch (ConnectionException) {
            // Left pending: the payment may still verify when the customer reloads the callback.
            return false;
        }

        $code = (int) $response->json('data.code');

        if ($code !== self::SUCCESS && $code !== self::ALREADY_VERIFIED) {
            $order->update([
                'status' => Order::STATUS_FAILED,
                'meta' => array_merge($order->meta, ['error' => $this->errorOf($response->json())]),
            ]);

            return false;
        }

        $this->orders->markPaid($order, (string) $response->json('data.ref_id'));

        return true;
    }

    /**
     * Whole Toman for a nano-USD amount, rounded up.
     */
    public function toman(int $amount): int
    {
        return BigDecimal::ofUnscaledValue($amount, Money::SCALE)
            ->multipliedBy((string) config('services.zarinpal.toman_per_usd'))
            ->toScale(0, RoundingMode::Up)
            ->toInt();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())->acceptJson()->asJson()->timeout(20);
    }

    private function baseUrl(): string
    {
        return config('services.zarinpal.sandbox') ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function errorOf(?array $body): string
    {
        $errors = $body['errors'] ?? [];

        return is_array($errors) && isset($errors['message']) ? "{$errors['message']} ({$errors['code']})" : 'کد '.($body['data']['code'] ?? 'نامشخص');
    }

    private function fail(Order $order, string $message): never
    {
        $order->update(['status' => Order::STATUS_FAILED]);

        throw ValidationException::withMessages(['payment' => $message]);
    }
}
