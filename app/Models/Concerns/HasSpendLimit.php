<?php

namespace App\Models\Concerns;

use App\Services\BudgetService;
use App\Support\BudgetPeriod;
use Carbon\CarbonImmutable;

/**
 * A spend limit (nano-USD) that applies per `spend_limit_period`. The model keeps a running
 * `period_spent` counter for the period starting at `period_starts_at`; when the stored period
 * is not the current one, nothing has been spent yet in the current period.
 *
 * @property ?int $spend_limit
 * @property BudgetPeriod $spend_limit_period
 * @property int $period_spent
 * @property ?CarbonImmutable $period_starts_at
 * @property int $spend_alert_level
 */
trait HasSpendLimit
{
    /** Alert levels, in percent of the limit. */
    public const SPEND_ALERT_LEVELS = [80, 100];

    /**
     * Changing the limit or its period rebuilds the counter, so a new monthly limit
     * already counts what was spent earlier this month.
     */
    protected static function bootHasSpendLimit(): void
    {
        static::updated(function (self $model) {
            if ($model->wasChanged(['spend_limit', 'spend_limit_period'])) {
                app(BudgetService::class)->recalculate($model);
            }
        });
    }

    protected function initializeHasSpendLimit(): void
    {
        $this->mergeCasts([
            'spend_limit' => 'integer',
            'spend_limit_period' => BudgetPeriod::class,
            'period_spent' => 'integer',
            'period_starts_at' => 'immutable_datetime',
            'spend_alert_level' => 'integer',
        ]);

        $this->attributes += [
            'spend_limit_period' => $this->defaultSpendLimitPeriod()->value,
            'period_spent' => 0,
            'spend_alert_level' => 0,
        ];
    }

    abstract protected function defaultSpendLimitPeriod(): BudgetPeriod;

    public function spentThisPeriod(): int
    {
        $start = $this->spend_limit_period->startsAt();

        if ($start === null) {
            return $this->period_spent;
        }

        return $this->period_starts_at?->equalTo($start) ? $this->period_spent : 0;
    }

    public function isOverSpendLimit(): bool
    {
        return $this->spend_limit !== null && $this->spentThisPeriod() >= $this->spend_limit;
    }

    /**
     * The highest alert level (80 or 100) reached this period, or 0.
     */
    public function spendAlertLevelReached(): int
    {
        if (! $this->spend_limit) {
            return $this->spend_limit === 0 ? 100 : 0;
        }

        $percent = $this->spentThisPeriod() / $this->spend_limit * 100;

        return collect(self::SPEND_ALERT_LEVELS)->filter(fn (int $level) => $percent >= $level)->last() ?? 0;
    }
}
