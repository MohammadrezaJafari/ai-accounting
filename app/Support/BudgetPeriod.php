<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use IntlCalendar;

/**
 * The window a spend limit applies to. Days and months follow the display timezone
 * (Tehran); months are Jalali months (a monthly limit resets on the 1st of Farvardin, Ordibehesht, …).
 */
enum BudgetPeriod: string
{
    case Total = 'total';
    case Daily = 'daily';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Total => 'کل',
            self::Daily => 'روزانه',
            self::Monthly => 'ماهانه',
        };
    }

    /**
     * @param  list<self>  $except
     * @return array<string, string> value => label, for select inputs
     */
    public static function options(array $except = []): array
    {
        return collect(self::cases())
            ->reject(fn (self $period) => in_array($period, $except, true))
            ->mapWithKeys(fn (self $period) => [$period->value => $period->label()])
            ->all();
    }

    /**
     * Start of the period containing `$now` (UTC), or null for a lifetime limit.
     */
    public function startsAt(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now());

        return match ($this) {
            self::Total => null,
            self::Daily => $now->setTimezone(self::timezone())->startOfDay()->utc(),
            self::Monthly => self::jalaliMonthStart($now, 0),
        };
    }

    /**
     * When the period containing `$now` ends and the counter starts over (UTC), or null for a lifetime limit.
     */
    public function endsAt(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now());

        return match ($this) {
            self::Total => null,
            self::Daily => $now->setTimezone(self::timezone())->startOfDay()->addDay()->utc(),
            self::Monthly => self::jalaliMonthStart($now, 1),
        };
    }

    private static function timezone(): string
    {
        return config('billing.display_timezone');
    }

    private static function jalaliMonthStart(CarbonImmutable $now, int $monthsAhead): CarbonImmutable
    {
        $calendar = IntlCalendar::createInstance(self::timezone(), 'fa_IR@calendar=persian');
        $calendar->setTime($now->getTimestampMs());
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $calendar->set(IntlCalendar::FIELD_HOUR_OF_DAY, 0);
        $calendar->set(IntlCalendar::FIELD_MINUTE, 0);
        $calendar->set(IntlCalendar::FIELD_SECOND, 0);
        $calendar->set(IntlCalendar::FIELD_MILLISECOND, 0);
        $calendar->add(IntlCalendar::FIELD_MONTH, $monthsAhead);

        return CarbonImmutable::createFromTimestampMs($calendar->getTime(), 'UTC');
    }
}
