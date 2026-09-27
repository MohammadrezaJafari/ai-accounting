<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Services\Agents\NewsMonitorAgent;
use App\Support\AgentDriver;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * The built-in news monitor and its unit packages. Marketplace agents (HTTP services) are
 * added in the admin panel. Review prices after watching the real cost per report (Agents → runs).
 */
class AgentSeeder extends Seeder
{
    public function run(): void
    {
        $news = Agent::query()->updateOrCreate(['slug' => Agent::NEWS_MONITOR], [
            'driver' => AgentDriver::Builtin,
            'name' => 'پایش خبر',
            'tagline' => 'خبرهای تازهٔ منابع شما، خلاصه و دسته‌بندی‌شده',
            'description' => "منابع خبری و کلیدواژه‌های شما را در ساعت‌هایی که تعیین می‌کنید بررسی می‌کند و از خبرهای تازه یک گزارش خلاصه می‌سازد.\n\n- فید RSS یا صفحهٔ اول هر سایت خبری\n- فیلتر با کلیدواژه و کلیدواژهٔ حذفی\n- خبری که یک بار گزارش شده تکرار نمی‌شود\n- اجرایی که خبر تازه‌ای پیدا نکند اعتباری مصرف نمی‌کند",
            'icon' => 'feed',
            'category' => 'پایش و گزارش',
            'publisher_name' => null,
            'unit_name' => 'گزارش',
            'config_schema' => NewsMonitorAgent::CONFIG_SCHEMA,
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
