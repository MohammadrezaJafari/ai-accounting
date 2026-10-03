<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublisherPayoutResource;
use App\Models\App;
use App\Services\Publishers\PublisherDeposits;
use App\Services\Publishers\PublisherEarnings;
use App\Support\Money;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
                ...collect($summary)->only(['revenue', 'share', 'model_cost', 'earned', 'paid', 'deposits', 'balance'])->map(fn (int $amount) => Money::toUsd($amount))->all(),
            ],
            'test_runs_blocked' => $earnings->testRunsBlocked($organization),
            'test_run_debt_limit' => Money::toUsd(Money::fromUsd(config('billing.publishers.test_run_debt_limit_usd'))),
            'payout_min' => Money::toUsd(PublisherEarnings::payoutMinimum()),
            'payout_due' => $summary['balance'] >= PublisherEarnings::payoutMinimum(),
            'deposit_limits' => [
                'min' => Money::toUsd(Money::fromUsd(config('billing.publishers.deposit_min_usd'))),
                'max' => Money::toUsd(Money::fromUsd(config('billing.publishers.deposit_max_usd'))),
            ],
            'daily' => array_map(fn (array $day) => [...$day, 'earned' => Money::toUsd($day['earned'])], $earnings->daily($organization)),
            'payouts' => PublisherPayoutResource::collection($organization->payouts()->with('app')->latest('paid_at')->latest('id')->limit(50)->get()),
        ]);
    }

    /**
     * Cover the publisher's debt from one of the organization's app wallets.
     */
    public function deposit(Request $request, PublisherDeposits $deposits): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageBilling);

        $organization = $this->organization($request);
        $data = $request->validate([
            'app_id' => ['required', 'integer', Rule::exists('apps', 'id')->where('organization_id', $organization->id)],
            'amount' => [
                'required', 'numeric',
                'min:'.config('billing.publishers.deposit_min_usd'),
                'max:'.config('billing.publishers.deposit_max_usd'),
            ],
        ], ['app_id.exists' => 'این اپ متعلق به سازمان شما نیست.']);

        $deposit = $deposits->deposit($organization, App::query()->findOrFail($data['app_id']), Money::fromUsd($data['amount']), $request->user());

        return (new PublisherPayoutResource($deposit))->response()->setStatusCode(201);
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
