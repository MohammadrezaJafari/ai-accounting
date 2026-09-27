<?php

namespace App\Services\Agents;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Instances run at whole hours of the day in the display timezone (Tehran), optionally only on
 * some days of the week (0 = Sunday … 6 = Saturday; e.g. Saturday–Wednesday for the Iranian work week).
 */
final class AgentSchedule
{
    /**
     * The next run time (UTC) after `$now`, or null when no hours are set (manual runs only).
     *
     * @param  list<int>  $hours
     * @param  list<int>  $days  empty = every day
     */
    public static function next(array $hours, array $days = [], ?CarbonInterface $now = null): ?CarbonImmutable
    {
        if ($hours === []) {
            return null;
        }

        sort($hours);
        $now = CarbonImmutable::instance($now ?? now());
        $today = $now->setTimezone(config('billing.display_timezone'))->startOfDay();

        foreach (range(0, 7) as $offset) {
            $day = $today->addDays($offset);

            if ($days !== [] && ! in_array($day->dayOfWeek, $days, true)) {
                continue;
            }

            foreach ($hours as $hour) {
                $candidate = $day->setTime($hour, 0);

                if ($candidate->greaterThan($now)) {
                    return $candidate->utc();
                }
            }
        }

        return null;
    }
}
