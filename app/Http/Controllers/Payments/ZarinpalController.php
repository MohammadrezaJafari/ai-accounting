<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\ZarinpalGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where Zarinpal sends the customer back: verify the payment, then return to the panel's
 * wallet page with the outcome (`?order={id}&payment=paid|failed`).
 */
class ZarinpalController extends Controller
{
    public function callback(Request $request, Order $order, ZarinpalGateway $zarinpal): RedirectResponse
    {
        abort_unless($order->gateway === $zarinpal->name(), 404);

        $paid = $zarinpal->verify($order, (string) $request->query('Authority'), $request->query('Status') === 'OK');

        return redirect()->away(rtrim(config('billing.panel_url'), '/').'/wallet?'.http_build_query([
            'order' => $order->id,
            'payment' => $paid ? 'paid' : 'failed',
        ]));
    }
}
