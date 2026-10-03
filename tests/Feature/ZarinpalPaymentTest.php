<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZarinpalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private App $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.payment_gateway' => 'zarinpal',
            'billing.panel_url' => 'https://panel.test',
            'services.zarinpal.merchant_id' => 'merchant-1',
            'services.zarinpal.toman_per_usd' => '95000',
        ]);

        $user = User::factory()->inOrganization()->create();
        $this->wallet = $user->currentOrganization->apps()->create(['name' => 'App']);
        Sanctum::actingAs($user);
    }

    private function order(string $verifyCode = '100'): Order
    {
        Http::fake([
            'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A0000123'], 'errors' => []]),
            'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => (int) $verifyCode, 'ref_id' => 987654], 'errors' => []]),
        ]);

        $id = $this->postJson('/api/v1/orders', ['app_id' => $this->wallet->id, 'amount' => '10.50'])->assertCreated()
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.meta.payment_url', 'https://payment.zarinpal.com/pg/StartPay/A0000123')
            ->json('data.id');

        return Order::query()->findOrFail($id);
    }

    public function test_a_top_up_is_charged_in_toman_and_paid_after_verification(): void
    {
        $order = $this->order();

        // $10.50 at 95,000 Toman.
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), 'request.json')
            && $request['amount'] === 997500
            && $request['currency'] === 'IRT'
            && $request['callback_url'] === route('payments.zarinpal.callback', $order));

        $this->get("/payments/zarinpal/callback/{$order->id}?Authority=A0000123&Status=OK")
            ->assertRedirect("https://panel.test/wallet?order={$order->id}&payment=paid");

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), 'verify.json') && $request['amount'] === 997500);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame('987654', $order->fresh()->gateway_ref);
        $this->assertSame(Money::fromUsd('10.50'), $this->wallet->fresh()->balance);

        // Returning to the callback again does not credit twice.
        $this->get("/payments/zarinpal/callback/{$order->id}?Authority=A0000123&Status=OK")->assertRedirect();
        $this->assertSame(Money::fromUsd('10.50'), $this->wallet->fresh()->balance);
    }

    public function test_a_cancelled_payment_fails_the_order(): void
    {
        $order = $this->order();

        $this->get("/payments/zarinpal/callback/{$order->id}?Authority=A0000123&Status=NOK")
            ->assertRedirect("https://panel.test/wallet?order={$order->id}&payment=failed");

        Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), 'verify.json'));
        $this->assertSame(Order::STATUS_FAILED, $order->fresh()->status);
        $this->assertSame(0, $this->wallet->fresh()->balance);
    }

    public function test_a_payment_zarinpal_does_not_verify_is_not_credited(): void
    {
        $order = $this->order('-51');

        $this->get("/payments/zarinpal/callback/{$order->id}?Authority=A0000123&Status=OK")->assertRedirect();

        $this->assertSame(Order::STATUS_FAILED, $order->fresh()->status);
        $this->assertSame(0, $this->wallet->fresh()->balance);
    }

    public function test_an_authority_of_another_payment_is_ignored(): void
    {
        $order = $this->order();

        $this->get("/payments/zarinpal/callback/{$order->id}?Authority=OTHER&Status=OK")->assertRedirect();

        Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), 'verify.json'));
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_an_unconfigured_gateway_refuses_orders(): void
    {
        config(['services.zarinpal.merchant_id' => null]);
        Http::fake();

        $this->postJson('/api/v1/orders', ['app_id' => $this->wallet->id, 'amount' => '10'])->assertJsonValidationErrors('payment');
        Http::assertNothingSent();
    }
}
