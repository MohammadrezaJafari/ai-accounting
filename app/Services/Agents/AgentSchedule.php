<?php

namespace App\Services\Agents;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Instances run at whole hours of the day in the display timezone (Tehran).
 */
final class AgentSchedule
{
    /**
     * The next run time (UTC) after `$now`, or null when no hours are set (manual runs only).
     *
     * @param  list<int>  $hours
     */
    public static function next(array $hours, ?CarbonInterface $now = null): ?CarbonImmutable
    {
        if ($hours === []) {
            return null;
        }

        sort($hours);
        $now = CarbonImmutable::instance($now ?? now());
        $today = $now->setTimezone(config('billing.display_timezone'))->startOfDay();

        foreach ([0, 1] as $days) {
            foreach ($hours as $hour) {
                $candidate = $today->addDays($days)->setTime($hour, 0);

                if ($candidate->greaterThan($now)) {
                    return $candidate->utc();
                }
            }
        }

        return null;
    }
}
