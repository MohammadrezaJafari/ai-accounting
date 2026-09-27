<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResource;
use App\Models\App;
use App\Support\BudgetPeriod;
use App\Support\Money;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AppController extends Controller
{
    use Concerns;

    public function index(Request $request): AnonymousResourceCollection
    {
        return AppResource::collection($this->organization($request)->apps()->withCount('apiKeys')->latest()->get());
    }

    public function store(Request $request): AppResource
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return new AppResource($this->organization($request)->apps()->create($data + ['user_id' => $request->user()->id]));
    }

    public function show(Request $request, App $app): AppResource
    {
        return new AppResource($this->ownedApp($request, $app)->loadCount('apiKeys'));
    }

    /**
     * Name, description and status need the apps permission; the spend limit needs the billing permission.
     */
    public function update(Request $request, App $app): AppResource
    {
        $this->ownedApp($request, $app);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'spend_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'spend_limit_period' => ['sometimes', Rule::enum(BudgetPeriod::class)->except([BudgetPeriod::Total])],
        ]);

        if (array_intersect_key($data, array_flip(['name', 'description', 'is_active']))) {
            $this->authorizeTo(OrganizationPermission::ManageApps);
        }

        if (array_intersect_key($data, array_flip(['spend_limit', 'spend_limit_period']))) {
            $this->authorizeTo(OrganizationPermission::ManageBilling);
        }

        if (array_key_exists('spend_limit', $data)) {
            $data['spend_limit'] = Money::fromUsd($data['spend_limit']);
        }

        $app->update($data);

        return new AppResource($app->refresh()->loadCount('apiKeys'));
    }

    public function destroy(Request $request, App $app): JsonResponse
    {
        $this->ownedApp($request, $app);
        $this->authorizeTo(OrganizationPermission::ManageApps);
        abort_if($app->balance > 0, 422, 'Apps with a remaining balance cannot be deleted.');
        $app->delete();

        return response()->json(['ok' => true]);
    }
}
