<?php

namespace App\Services\Publishers;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Organization;
use App\Models\PublisherPayout;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a publisher's agents sold and earned. Each run records the publisher's share of its
 * revenue and the model cost charged back to it (every run, test runs included); earnings are
 * the difference and the balance is what has not been paid out yet (it can be negative);
 * deposits from the publisher's own wallets raise it.
 */
class PublisherEarnings
{
    /**
     * @return array{units: int, revenue: int, share: int, model_cost: int, earned: int, paid: int, deposits: int, balance: int, customers: int, live_agents: int}
     */
    public function summary(Organization $publisher): array
    {
        $sales = $this->paidRuns($publisher)->toBase()
            ->selectRaw('COALESCE(SUM(units), 0) as units, COALESCE(SUM(revenue), 0) as revenue, COUNT(DISTINCT organization_id) as customers')
            ->first();
        $ledger = $this->runs($publisher)->toBase()
            ->selectRaw('COALESCE(SUM(publisher_share), 0) as share, COALESCE(SUM(publisher_cost), 0) as model_cost')
            ->first();
        $earned = (int) $ledger->share - (int) $ledger->model_cost;
        $ledgerTotals = $publisher->payouts()->toBase()->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
        $paid = (int) ($ledgerTotals[PublisherPayout::TYPE_PAYOUT] ?? 0);
        $deposits = (int) ($ledgerTotals[PublisherPayout::TYPE_DEPOSIT] ?? 0);

        return [
            'units' => (int) $sales->units,
            'revenue' => (int) $sales->revenue,
            'share' => (int) $ledger->share,
            'model_cost' => (int) $ledger->model_cost,
            'earned' => $earned,
            'paid' => $paid,
            'deposits' => $deposits,
            'balance' => $earned - $paid + $deposits,
            'customers' => (int) $sales->customers,
            'live_agents' => $publisher->publishedAgents()->active()->count(),
        ];
    }

    /**
     * Whether the publisher owes more than the platform allows for trying its agents: its test
     * runs stop until sales, a deposit (or a settlement) bring the balance back up. Customers' runs go on.
     */
    public function testRunsBlocked(Organization $publisher): bool
    {
        return $this->summary($publisher)['balance'] < -Money::fromUsd(config('billing.publishers.test_run_debt_limit_usd'));
    }

    /**
     * Whether the balance has reached the minimum the platform pays out.
     */
    public function payoutDue(Organization $publisher): bool
    {
        return $this->summary($publisher)['balance'] >= self::payoutMinimum();
    }

    public static function payoutMinimum(): int
    {
        return Money::fromUsd(config('billing.publishers.payout_min_usd'));
    }

    /**
     * Net earnings per day (Tehran time) over the last `$days` days, oldest first.
     *
     * @return list<array{date: string, earned: int, units: int}>
     */
    public function daily(Organization $publisher, int $days = 30): array
    {
        $timezone = config('billing.display_timezone');
        $start = CarbonImmutable::now($timezone)->startOfDay()->subDays($days - 1);
        $series = [];

        for ($day = 0; $day < $days; $day++) {
            $series[$start->addDays($day)->toDateString()] = ['date' => $start->addDays($day)->toDateString(), 'earned' => 0, 'units' => 0];
        }

        $this->runs($publisher)->where('created_at', '>=', $start->utc())->get(['created_at', 'trigger', 'units', 'publisher_share', 'publisher_cost'])
            ->each(function (AgentRun $run) use (&$series, $timezone) {
                $date = $run->created_at->setTimezone($timezone)->toDateString();

                if (isset($series[$date])) {
                    $series[$date]['earned'] += $run->publisher_share - $run->publisher_cost;
                    $series[$date]['units'] += $run->isTest() ? 0 : $run->units;
                }
            });

        return array_values($series);
    }

    /**
     * How one agent is doing: sales, the publisher's share, model cost (test runs included)
     * and net earnings, and how its customers' runs end.
     *
     * @return array{units: int, revenue: int, share: int, cost: int, earned: int, avg_run_cost: ?int, max_run_cost: ?int, customers: int, active_instances: int, runs: array<string, int>, failure_rate: ?float}
     */
    public function forAgent(Agent $agent): array
    {
        $runs = $agent->runs()->where('trigger', '!=', AgentRun::TRIGGER_TEST);
        $totals = (clone $runs)->toBase()
            ->selectRaw('COALESCE(SUM(units), 0) as units, COALESCE(SUM(revenue), 0) as revenue, COUNT(DISTINCT organization_id) as customers')
            ->first();
        $ledger = $agent->runs()->toBase()
            ->selectRaw('COALESCE(SUM(publisher_share), 0) as share, COALESCE(SUM(publisher_cost), 0) as cost')
            ->first();
        $runCosts = $agent->runs()->whereNotNull('finished_at')->where('publisher_cost', '>', 0)->toBase()
            ->selectRaw('AVG(publisher_cost) as average, MAX(publisher_cost) as highest')
            ->first();
        $statuses = (clone $runs)->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total);
        $finished = $statuses->only([AgentRun::STATUS_SUCCEEDED, AgentRun::STATUS_EMPTY, AgentRun::STATUS_FAILED])->sum();

        return [
            'units' => (int) $totals->units,
            'revenue' => (int) $totals->revenue,
            'share' => (int) $ledger->share,
            'cost' => (int) $ledger->cost,
            'earned' => (int) $ledger->share - (int) $ledger->cost,
            'avg_run_cost' => $runCosts->average === null ? null : (int) round($runCosts->average),
            'max_run_cost' => $runCosts->highest === null ? null : (int) $runCosts->highest,
            'customers' => (int) $totals->customers,
            'active_instances' => $agent->instances()->where('is_test', false)->where('is_active', true)->count(),
            'runs' => $statuses->all(),
            'failure_rate' => $finished > 0 ? round(($statuses[AgentRun::STATUS_FAILED] ?? 0) / $finished * 100, 1) : null,
        ];
    }

    /**
     * Every run of the publisher's agents, test runs included.
     */
    private function runs(Organization $publisher): Builder
    {
        return AgentRun::query()->whereIn('agent_id', $publisher->publishedAgents()->select('id'));
    }

    /**
     * Runs customers paid for.
     */
    private function paidRuns(Organization $publisher): Builder
    {
        return $this->runs($publisher)->where('trigger', '!=', AgentRun::TRIGGER_TEST)->where('units', '>', 0);
    }
}
