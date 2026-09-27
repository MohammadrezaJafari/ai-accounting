<?php

use App\Support\BudgetPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spend limits per period. `period_spent` counts the charges since `period_starts_at`
 * (the start of the current day or Jalali month, or null for a lifetime limit);
 * `spend_alert_level` remembers which alert (80 / 100 %) was already sent this period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->bigInteger('spend_limit')->nullable()->after('balance');
            $table->string('spend_limit_period', 10)->default(BudgetPeriod::Monthly->value)->after('spend_limit');
            $table->bigInteger('period_spent')->default(0)->after('spend_limit_period');
            $table->timestamp('period_starts_at')->nullable()->after('period_spent');
            $table->unsignedTinyInteger('spend_alert_level')->default(0)->after('period_starts_at');
        });

        Schema::table('app_api_keys', function (Blueprint $table) {
            $table->string('spend_limit_period', 10)->default(BudgetPeriod::Total->value)->after('spend_limit');
            $table->bigInteger('period_spent')->default(0)->after('spent');
            $table->timestamp('period_starts_at')->nullable()->after('period_spent');
            $table->unsignedTinyInteger('spend_alert_level')->default(0)->after('period_starts_at');
        });

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->index(['app_api_key_id', 'created_at']);
        });

        // Existing key limits are lifetime limits; apps start counting from this Jalali month.
        DB::table('app_api_keys')->update(['period_spent' => DB::raw('spent')]);

        $monthStart = BudgetPeriod::Monthly->startsAt();
        DB::table('apps')->update(['period_starts_at' => $monthStart]);
        DB::table('usage_logs')
            ->where('created_at', '>=', $monthStart)
            ->groupBy('app_id')
            ->selectRaw('app_id, SUM(charge) as charge')
            ->get()
            ->each(fn ($row) => DB::table('apps')->where('id', $row->app_id)->update(['period_spent' => (int) $row->charge]));
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropIndex(['app_api_key_id', 'created_at']);
        });
        Schema::table('app_api_keys', function (Blueprint $table) {
            $table->dropColumn(['spend_limit_period', 'period_spent', 'period_starts_at', 'spend_alert_level']);
        });
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['spend_limit', 'spend_limit_period', 'period_spent', 'period_starts_at', 'spend_alert_level']);
        });
    }
};
