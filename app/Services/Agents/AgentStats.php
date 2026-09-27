<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

/**
 * What an agent earns and costs, for setting unit prices: revenue is the paid value of used
 * units, cost includes every run's model calls (also runs that delivered nothing) and the
 * publisher's share is what a third-party publisher is owed; the margin is what is left.
 */
class AgentStats
{
    /**
     * @return array{reports: int, revenue: int, cost: int, publisher_share: int, margin: int, margin_percent: ?float, avg_cost: ?int, p95_cost: ?int}
     */
    public function for(Agent $agent): array
    {
        $runs = $agent->runs()->toBase()
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) as reports, COALESCE(SUM(revenue), 0) as revenue, COALESCE(SUM(cost), 0) as cost', [AgentRun::STATUS_SUCCEEDED])
            ->first();

        $costs = $agent->runs()->where('status', AgentRun::STATUS_SUCCEEDED)->orderBy('cost')->pluck('cost')->map(fn ($cost) => (int) $cost);
        $revenue = (int) $runs->revenue;
        $cost = (int) $runs->cost;
        $publisherShare = intdiv($revenue * $agent->revenue_share, 100);
        $margin = $revenue - $cost - $publisherShare;

        return [
            'reports' => (int) $runs->reports,
            'revenue' => $revenue,
            'cost' => $cost,
            'publisher_share' => $publisherShare,
            'margin' => $margin,
            'margin_percent' => $revenue > 0 ? round($margin / $revenue * 100, 1) : null,
            'avg_cost' => $costs->isEmpty() ? null : (int) round($costs->avg()),
            'p95_cost' => $costs->isEmpty() ? null : $costs[(int) ceil($costs->count() * 0.95) - 1],
        ];
    }
}
