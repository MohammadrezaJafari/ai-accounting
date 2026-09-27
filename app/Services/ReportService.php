<?php

namespace App\Services;

use App\Models\App;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Usage aggregates. Cost/profit are only included for admin reports.
 */
class ReportService
{
    public function totals(Builder $query, bool $withCost): array
    {
        $row = $query->toBase()->selectRaw($this->aggregates())->first();

        return $this->format((array) $row, $withCost);
    }

    public function daily(Builder $query, bool $withCost): array
    {
        return $query->toBase()
            ->selectRaw('DATE(created_at) as day, '.$this->aggregates())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['day' => (string) $row->day] + $this->format((array) $row, $withCost))
            ->all();
    }

    /**
     * @param  'model'|'app_id'|'provider_id'|'app_api_key_id'  $column
     */
    public function grouped(Builder $query, string $column, bool $withCost): array
    {
        $rows = $query->toBase()
            ->selectRaw("$column as grp, ".$this->aggregates())
            ->groupBy($column)
            ->orderByDesc('charge')
            ->get();

        $names = match ($column) {
            'app_id' => App::query()->whereIn('id', $rows->pluck('grp'))->pluck('name', 'id'),
            'provider_id' => DB::table('providers')->whereIn('id', $rows->pluck('grp'))->pluck('name', 'id'),
            'app_api_key_id' => DB::table('app_api_keys')->whereIn('id', $rows->pluck('grp'))->pluck('name', 'id'),
            default => null,
        };

        return $rows->map(fn ($row) => [
            'key' => $row->grp,
            'label' => $names ? ($names[$row->grp] ?? '#'.$row->grp) : (string) $row->grp,
        ] + $this->format((array) $row, $withCost))->all();
    }

    private function aggregates(): string
    {
        return 'COUNT(*) as requests,'
            .' SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors,'
            .' COALESCE(SUM(input_tokens + cached_input_tokens + cache_write_tokens), 0) as input_tokens,'
            .' COALESCE(SUM(output_tokens), 0) as output_tokens,'
            .' COALESCE(SUM(charge), 0) as charge,'
            .' COALESCE(SUM(cost), 0) as cost';
    }

    private function format(array $row, bool $withCost): array
    {
        $data = [
            'requests' => (int) ($row['requests'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
            'input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'charge' => Money::toUsd((int) ($row['charge'] ?? 0)),
        ];

        if ($withCost) {
            $data['cost'] = Money::toUsd((int) ($row['cost'] ?? 0));
            $data['profit'] = Money::toUsd((int) ($row['charge'] ?? 0) - (int) ($row['cost'] ?? 0));
        }

        return $data;
    }
}
