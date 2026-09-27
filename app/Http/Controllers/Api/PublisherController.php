<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublisherPayoutResource;
use App\Services\Publishers\PublisherEarnings;
use App\Support\Money;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The current organization as a publisher: its public profile, earnings and payouts.
 * Members who publish agents or handle billing see earnings; payout details are billing's.
 */
class PublisherController extends Controller
{
    use Concerns;

    public function show(Request $request, PublisherEarnings $earnings): JsonResponse
    {
        $this->authorizeToAny();
        $organization = $this->organization($request);
        $summary = $earnings->summary($organization);

        return response()->json([
            'profile' => [
                'publisher_name' => $organization->publisher_name,
                'publisher_url' => $organization->publisher_url,
                'support_email' => $organization->support_email,
                'payout_details' => Gate::allows(OrganizationPermission::ManageBilling->value) ? $organization->payout_details : null,
            ],
            'summary' => [
                ...$summary,
                ...collect($summary)->only(['revenue', 'share', 'model_cost', 'earned', 'paid', 'balance'])->map(fn (int $amount) => Money::toUsd($amount))->all(),
            ],
            'test_runs_blocked' => $earnings->testRunsBlocked($organization),
            'test_run_debt_limit' => Money::toUsd(Money::fromUsd(config('billing.publishers.test_run_debt_limit_usd'))),
            'daily' => array_map(fn (array $day) => [...$day, 'earned' => Money::toUsd($day['earned'])], $earnings->daily($organization)),
            'payouts' => PublisherPayoutResource::collection($organization->payouts()->latest('paid_at')->limit(50)->get()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeToAny();

        $data = $request->validate([
            'publisher_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'publisher_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'payout_details' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('payout_details', $data)) {
            $this->authorizeTo(OrganizationPermission::ManageBilling);
        }

        $this->organization($request)->update($data);

        return response()->json(['ok' => true]);
    }

    private function authorizeToAny(): void
    {
        abort_unless(
            Gate::any([OrganizationPermission::PublishAgents->value, OrganizationPermission::ManageBilling->value]),
            403,
            'نقش شما در این سازمان اجازهٔ این کار را نمی‌دهد.',
        );
    }
}
