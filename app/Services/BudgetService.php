<?php

namespace App\Services;

use App\Models\App;
use App\Models\AppApiKey;
use App\Models\UsageLog;
use App\Notifications\SpendLimitAlert;
use App\Support\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Keeps the per-period spend counters of apps and API keys, and alerts the organization
 * when a spend limit reaches 80% and 100% (once per level per period).
 */
class BudgetService
{
    /**
     * Add a request's charge to the current period, starting a new period when the stored one has ended.
     */
    public function record(App|AppApiKey $budgeted, int $charge): void
    {
        $start = $budgeted->spend_limit_period->startsAt();
        $table = $budgeted->getTable();

        if ($start === null) {
            DB::table($table)->where('id', $budgeted->id)->increment('period_spent', $charge);
        } else {
            $stamp = $start->format('Y-m-d H:i:s');

            // period_starts_at is assigned last: MySQL evaluates SET clauses left to right.
            DB::update(
                "update {$table} set
                    period_spent = case when period_starts_at = ? then period_spent + ? else ? end,
                    spend_alert_level = case when period_starts_at = ? then spend_alert_level else 0 end,
                    period_starts_at = ?
                where id = ?",
                [$stamp, $charge, $charge, $stamp, $stamp, $budgeted->id],
            );
        }

        if ($budgeted->spend_limit !== null) {
            $this->alertIfNeeded($budgeted);
        }
    }

    /**
     * Rebuild the current period's counter from the usage logs (after the limit or its period changed).
     */
    public function recalculate(App|AppApiKey $budgeted): void
    {
        $start = $budgeted->spend_limit_period->startsAt();

        $spent = match (true) {
            $start === null && $budgeted instanceof AppApiKey => (int) DB::table('app_api_keys')->where('id', $budgeted->id)->value('spent'),
            default => (int) UsageLog::query()
                ->where($budgeted instanceof AppApiKey ? 'app_api_key_id' : 'app_id', $budgeted->id)
                ->when($start, fn ($query) => $query->where('created_at', '>=', $start))
                ->sum('charge'),
        };

        $budgeted->forceFill(['period_spent' => $spent, 'period_starts_at' => $start]);
        $budgeted->spend_alert_level = $budgeted->spendAlertLevelReached();

        DB::table($budgeted->getTable())->where('id', $budgeted->id)->update([
            'period_spent' => $spent,
            'period_starts_at' => $start,
            'spend_alert_level' => $budgeted->spend_alert_level,
        ]);
    }

    private function alertIfNeeded(App|AppApiKey $budgeted): void
    {
        $fresh = $budgeted->newQuery()->with($budgeted instanceof AppApiKey ? 'app.organization' : 'organization')->find($budgeted->id);
        $level = $fresh?->spendAlertLevelReached() ?? 0;

        if ($level <= $fresh?->spend_alert_level) {
            return;
        }

        // Only the request that raises the level sends the alert.
        $raised = $budgeted->newQuery()->whereKey($fresh->id)->where('spend_alert_level', '<', $level)->update(['spend_alert_level' => $level]);

        if (! $raised) {
            return;
        }

        $app = $fresh instanceof AppApiKey ? $fresh->app : $fresh;
        $roles = $fresh instanceof AppApiKey
            ? [OrganizationRole::Owner, OrganizationRole::Billing, OrganizationRole::Developer]
            : [OrganizationRole::Owner, OrganizationRole::Billing];
        $recipients = $app->organization?->membersWithRole($roles)->get() ?? collect();

        // A mail server outage must not fail the request that was already billed.
        rescue(fn () => Notification::send($recipients, new SpendLimitAlert($fresh, $level)));
    }
}
