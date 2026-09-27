<?php

namespace App\Services\Publishers;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a publisher's agents sold and earned. Earnings are the `publisher_share` recorded on
 * each paid run; the balance is what has not been paid out yet. Test runs never count.
 */
class PublisherEarnings
{
    /**
     * @return array{units: int, revenue: int, earned: int, paid: int, balance: int, customers: int, live_agents: int}
     */
    public function summary(Organization $publisher): array
    {
        $totals = $this->paidRuns($publisher)->toBase()
            ->selectRaw('COALESCE(SUM(units), 0) as units, COALESCE(SUM(revenue), 0) as revenue, COALESCE(SUM(publisher_share), 0) as earned, COUNT(DISTINCT organization_id) as customers')
            ->first();
        $paid = (int) $publisher->payouts()->sum('amount');

        return [
            'units' => (int) $totals->units,
            'revenue' => (int) $totals->revenue,
            'earned' => (int) $totals->earned,
            'paid' => $paid,
            'balance' => (int) $totals->earned - $paid,
            'customers' => (int) $totals->customers,
            'live_agents' => $publisher->publishedAgents()->active()->count(),
        ];
    }

    /**
     * Earnings per day (Tehran time) over the last `$days` days, oldest first.
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

        $this->paidRuns($publisher)->where('created_at', '>=', $start->utc())->get(['created_at', 'units', 'publisher_share'])
            ->each(function (AgentRun $run) use (&$series, $timezone) {
                $date = $run->created_at->setTimezone($timezone)->toDateString();

                if (isset($series[$date])) {
                    $series[$date]['earned'] += $run->publisher_share;
                    $series[$date]['units'] += $run->units;
                }
            });

        return array_values($series);
    }

    /**
     * How one agent is doing: sales, earnings, model cost and how its runs end.
     *
     * @return array{units: int, revenue: int, earned: int, cost: int, customers: int, active_instances: int, runs: array<string, int>, failure_rate: ?float}
     */
    public function forAgent(Agent $agent): array
    {
        $runs = $agent->runs()->where('trigger', '!=', AgentRun::TRIGGER_TEST);
        $totals = (clone $runs)->toBase()
            ->selectRaw('COALESCE(SUM(units), 0) as units, COALESCE(SUM(revenue), 0) as revenue, COALESCE(SUM(publisher_share), 0) as earned, COALESCE(SUM(cost), 0) as cost, COUNT(DISTINCT organization_id) as customers')
            ->first();
        $statuses = (clone $runs)->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total);
        $finished = $statuses->only([AgentRun::STATUS_SUCCEEDED, AgentRun::STATUS_EMPTY, AgentRun::STATUS_FAILED])->sum();

        return [
            'units' => (int) $totals->units,
            'revenue' => (int) $totals->revenue,
            'earned' => (int) $totals->earned,
            'cost' => (int) $totals->cost,
            'customers' => (int) $totals->customers,
            'active_instances' => $agent->instances()->where('is_test', false)->where('is_active', true)->count(),
            'runs' => $statuses->all(),
            'failure_rate' => $finished > 0 ? round(($statuses[AgentRun::STATUS_FAILED] ?? 0) / $finished * 100, 1) : null,
        ];
    }

    private function paidRuns(Organization $publisher): Builder
    {
        return AgentRun::query()
            ->whereIn('agent_id', $publisher->publishedAgents()->select('id'))
            ->where('trigger', '!=', AgentRun::TRIGGER_TEST)
            ->where('units', '>', 0);
    }
}
