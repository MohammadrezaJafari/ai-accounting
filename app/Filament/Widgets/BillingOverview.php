<?php

namespace App\Filament\Widgets;

use App\Models\App;
use App\Models\Order;
use App\Models\UsageLog;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BillingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $since = now()->subDays(30);
        $usage = UsageLog::query()->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as requests, COALESCE(SUM(charge), 0) as charge, COALESCE(SUM(cost), 0) as cost')
            ->first();

        $charge = (int) $usage->charge;
        $cost = (int) $usage->cost;
        $profit = $charge - $cost;
        $revenue = (int) Order::query()->where('status', Order::STATUS_PAID)->where('paid_at', '>=', $since)->sum('amount');
        $pending = Order::query()->where('status', Order::STATUS_PENDING)->count();

        return [
            Stat::make('سود ۳۰ روز', Money::format($profit))
                ->description($charge > 0 ? 'حاشیه '.round($profit / $charge * 100, 1).'٪' : 'هنوز مصرفی ثبت نشده')
                ->color('success'),
            Stat::make('مصرف مشتریان در ۳۰ روز', Money::format($charge))
                ->description('هزینهٔ ارائه‌دهنده‌ها: '.Money::format($cost).' · '.number_format((int) $usage->requests).' درخواست'),
            Stat::make('پرداختی مشتریان در ۳۰ روز', Money::format($revenue))
                ->description($pending > 0 ? $pending.' سفارش در انتظار تأیید' : 'سفارش معوقی نیست')
                ->color($pending > 0 ? 'warning' : null),
            Stat::make('موجودی باقی‌ماندهٔ اپ‌ها', Money::format((int) App::query()->sum('balance')))
                ->description('اعتبار پیش‌پرداختِ مصرف‌نشده'),
        ];
    }
}
