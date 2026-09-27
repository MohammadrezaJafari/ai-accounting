<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Models\AgentCredit;
use App\Models\App;
use App\Services\Agents\AgentCreditService;
use App\Support\Money;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    use Concerns;

    /**
     * Agent products with their packages and the organization's remaining units.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $credits = AgentCredit::query()->where('organization_id', $this->organization($request)->id)->pluck('units', 'agent_id');

        $agents = Agent::query()->active()->with('publisher')->orderBy('sort_order')
            ->with(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->get()
            ->each(fn (Agent $agent) => $agent->setAttribute('credits', $credits[$agent->id] ?? 0));

        return AgentResource::collection($agents)->additional(['delivery' => [
            'telegram_bot' => config('services.telegram.bot_username'),
            'bale_bot' => config('services.bale.bot_username'),
        ]]);
    }

    /**
     * Buy a unit package, paid from one of the organization's app wallets.
     */
    public function purchase(Request $request, Agent $agent, AgentCreditService $credits): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageBilling);
        abort_unless(Agent::query()->active()->whereKey($agent->id)->exists(), 404);

        $data = $request->validate([
            'package_id' => ['required', 'integer', Rule::exists('agent_packages', 'id')->where('agent_id', $agent->id)->where('is_active', true)],
            'app_id' => ['required', 'integer', 'exists:apps,id'],
        ]);

        $app = $this->ownedApp($request, App::query()->findOrFail($data['app_id']));
        $credit = $credits->purchase($this->organization($request), $app, $agent->packages()->findOrFail($data['package_id']), $request->user());

        return response()->json(['credits' => $credit->units, 'app_balance' => Money::toUsd($app->refresh()->balance)]);
    }
}
