<?php

namespace App\Filament\Widgets;

use App\Models\UsageLog;
use App\Support\Money;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class DailyProfitChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'مصرف، هزینه و سود روزانه (۳۰ روز، دلار)';

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $rows = UsageLog::query()
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COALESCE(SUM(charge), 0) as charge, COALESCE(SUM(cost), 0) as cost')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $days = collect(range(29, 0))->map(fn (int $ago) => now()->subDays($ago)->toDateString());
        $usd = fn (int $nanos) => (float) Money::toUsd($nanos);

        return [
            'datasets' => [
                ['label' => 'دریافتی', 'data' => $days->map(fn ($d) => $usd((int) ($rows[$d]->charge ?? 0)))->all(), 'borderColor' => '#60a5fa'],
                ['label' => 'هزینه', 'data' => $days->map(fn ($d) => $usd((int) ($rows[$d]->cost ?? 0)))->all(), 'borderColor' => '#f43f5e'],
                ['label' => 'سود', 'data' => $days->map(fn ($d) => $usd((int) ($rows[$d]->charge ?? 0) - (int) ($rows[$d]->cost ?? 0)))->all(), 'borderColor' => '#32946a'],
            ],
            'labels' => $days->map(fn ($d) => substr($d, 5))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
