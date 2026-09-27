<?php

namespace App\Support;

/**
 * Markups are stored in basis points (1% = 100 bps) and exposed as percentages.
 */
final class Percent
{
    public static function fromBps(?int $bps): ?float
    {
        return $bps === null ? null : $bps / 100;
    }

    public static function toBps(int|float|string|null $percent): ?int
    {
        return $percent === null || $percent === '' ? null : (int) round(((float) $percent) * 100);
    }
}
