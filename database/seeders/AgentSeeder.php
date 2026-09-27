<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Agent products and their unit packages. Review prices in the admin panel after watching
 * the real cost per report (Agents → runs).
 */
class AgentSeeder extends Seeder
{
    public function run(): void
    {
        $news = Agent::query()->updateOrCreate(['slug' => Agent::NEWS_MONITOR], [
            'name' => 'پایش خبر',
            'description' => 'منابع خبری و کلیدواژه‌های شما را هر روز بررسی می‌کند و از خبرهای تازه یک گزارش فارسی خلاصه می‌سازد.',
            'unit_name' => 'گزارش',
            'model' => 'gpt-4o-mini',
            'max_cost_per_run' => Money::fromUsd('0.25'),
            'is_active' => true,
        ]);

        foreach ([['۳۰ گزارش', 30, '9'], ['۱۰۰ گزارش', 100, '25'], ['۳۶۵ گزارش', 365, '75']] as $order => [$name, $units, $price]) {
            $news->packages()->updateOrCreate(['name' => $name], [
                'units' => $units,
                'price' => Money::fromUsd($price),
                'sort_order' => $order,
            ]);
        }
    }
}
