<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\App;
use App\Models\Order;
use App\Models\Package;
use App\Services\OrderService;
use App\Support\Money;
use App\Support\OrganizationPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    use Concerns;

    public function __construct(private OrderService $orders) {}

    /**
     * Orders for every app of the current organization, whoever placed them.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeTo(OrganizationPermission::ManageBilling);

        return OrderResource::collection(
            Order::query()
                ->whereIn('app_id', $this->organization($request)->apps()->select('id'))
                ->with(['app', 'package', 'user'])
                ->latest('id')
                ->paginate($this->perPage($request))
        );
    }

    /**
     * Buy a package (`package_id`) or top up any amount (`amount` in USD) for an app.
     */
    public function store(Request $request): OrderResource
    {
        $this->authorizeTo(OrganizationPermission::ManageBilling);

        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:apps,id'],
            'package_id' => ['required_without:amount', 'nullable', 'integer', 'exists:packages,id'],
            'amount' => ['required_without:package_id', 'nullable', 'numeric', 'gt:0'],
        ]);

        $app = $this->ownedApp($request, App::query()->findOrFail($data['app_id']));

        $order = ! empty($data['package_id'])
            ? $this->orders->forPackage($request->user(), $app, Package::query()->findOrFail($data['package_id']))
            : $this->orders->forCustomAmount($request->user(), $app, Money::fromUsd($data['amount']));

        return new OrderResource($order->load(['app', 'package']));
    }

    public function cancel(Request $request, Order $order): OrderResource
    {
        $this->ownedApp($request, $order->app);
        $this->authorizeTo(OrganizationPermission::ManageBilling);

        return new OrderResource($this->orders->cancel($order));
    }
}
