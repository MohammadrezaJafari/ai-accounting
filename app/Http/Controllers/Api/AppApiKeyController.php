<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppApiKeyResource;
use App\Models\App;
use App\Models\AppApiKey;
use App\Services\ApiKeyService;
use App\Support\BudgetPeriod;
use App\Support\Money;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AppApiKeyController extends Controller
{
    use Concerns;

    /**
     * Every key of every app in the current organization.
     */
    public function all(Request $request): AnonymousResourceCollection
    {
        $this->authorizeTo(OrganizationPermission::ManageKeys);

        return AppApiKeyResource::collection(
            AppApiKey::query()->with('app')->whereIn('app_id', $this->organization($request)->apps()->select('id'))->latest('id')->get()
        );
    }

    public function index(Request $request, App $app): AnonymousResourceCollection
    {
        $this->authorizeTo(OrganizationPermission::ManageKeys);

        return AppApiKeyResource::collection($this->ownedApp($request, $app)->apiKeys()->latest()->get());
    }

    public function store(Request $request, App $app, ApiKeyService $keys): JsonResponse
    {
        $this->ownedApp($request, $app);
        $this->authorizeTo(OrganizationPermission::ManageKeys);

        [$key, $plain] = $keys->create($app, $this->validated($request, true));

        return response()->json([
            'data' => new AppApiKeyResource($key),
            'plain_key' => $plain,
        ], 201);
    }

    public function update(Request $request, App $app, AppApiKey $key): AppApiKeyResource
    {
        $this->ownedApp($request, $app);
        $this->authorizeTo(OrganizationPermission::ManageKeys);
        abort_unless($key->app_id === $app->id, 404);

        $key->update($this->validated($request, false));

        return new AppApiKeyResource($key->refresh());
    }

    public function destroy(Request $request, App $app, AppApiKey $key): JsonResponse
    {
        $this->ownedApp($request, $app);
        $this->authorizeTo(OrganizationPermission::ManageKeys);
        abort_unless($key->app_id === $app->id, 404);
        $key->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'allowed_providers' => ['nullable', 'array'],
            'allowed_providers.*' => ['string', 'exists:providers,slug'],
            'allowed_models' => ['nullable', 'array'],
            'allowed_models.*' => ['string', 'exists:ai_models,public_id'],
            'spend_limit' => ['nullable', 'numeric', 'min:0'],
            'spend_limit_period' => ['sometimes', Rule::enum(BudgetPeriod::class)],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:'.config('billing.gateway_rate_limit.max_per_minute')],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        foreach (['allowed_providers', 'allowed_models'] as $field) {
            if (array_key_exists($field, $data) && empty($data[$field])) {
                $data[$field] = null;
            }
        }

        if (array_key_exists('spend_limit', $data)) {
            $data['spend_limit'] = Money::fromUsd($data['spend_limit']);
        }

        return $data;
    }
}
