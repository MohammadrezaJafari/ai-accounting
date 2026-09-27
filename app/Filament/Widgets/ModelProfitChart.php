<?php

namespace App\Filament\Widgets;

use App\Models\UsageLog;
use App\Support\Money;
use Filament\Widgets\ChartWidget;

class ModelProfitChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'سود به تفکیک مدل (۳۰ روز، دلار)';

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $rows = UsageLog::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('model, COALESCE(SUM(charge), 0) - COALESCE(SUM(cost), 0) as profit')
            ->groupBy('model')
            ->orderByDesc('profit')
            ->limit(12)
            ->get();

        return [
            'datasets' => [
                ['label' => 'سود', 'data' => $rows->map(fn ($r) => (float) Money::toUsd((int) $r->profit))->all(), 'backgroundColor' => '#32946a'],
            ],
            'labels' => $rows->pluck('model')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
