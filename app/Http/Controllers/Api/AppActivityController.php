<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UsageLogResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\App;
use App\Models\UsageLog;
use App\Support\OrganizationPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppActivityController extends Controller
{
    use Concerns;

    public function transactions(Request $request, App $app): AnonymousResourceCollection
    {
        $this->ownedApp($request, $app);
        $this->authorizeTo(OrganizationPermission::ManageBilling);

        return WalletTransactionResource::collection(
            $app->transactions()->with('creator')->latest('id')->paginate($this->perPage($request))
        );
    }

    /**
     * Usage logs of the organization's apps, optionally narrowed by app, key, model, status and date range.
     */
    public function usage(Request $request): AnonymousResourceCollection
    {
        $query = UsageLog::query()
            ->with(['app', 'apiKey'])
            ->whereIn('app_id', $this->organization($request)->apps()->select('id'))
            ->when($request->query('app_id'), fn ($q, $v) => $q->where('app_id', $v))
            ->when($request->query('api_key_id'), fn ($q, $v) => $q->where('app_api_key_id', $v))
            ->when($request->query('model'), fn ($q, $v) => $q->where('model', $v))
            ->when($request->query('errors_only'), fn ($q) => $q->where('status_code', '>=', 400))
            ->when($request->query('from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->latest('id');

        return UsageLogResource::collection($query->paginate($this->perPage($request)));
    }
}
