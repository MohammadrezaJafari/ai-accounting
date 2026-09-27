<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Money is stored as integer nano-USD (1 USD = 1e9 nanos) to keep per-token
 * prices exact. These helpers convert between nanos and decimal USD strings.
 */
final class Money
{
    public const SCALE = 9;

    public static function fromUsd(string|int|float|null $usd): ?int
    {
        if ($usd === null || $usd === '') {
            return null;
        }

        return BigDecimal::of((string) $usd)
            ->withPointMovedRight(self::SCALE)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    public static function toUsd(?int $nanos): ?string
    {
        if ($nanos === null) {
            return null;
        }

        $value = BigDecimal::ofUnscaledValue($nanos, self::SCALE)->strippedOfTrailingZeros();

        return $value->getScale() < 2 ? (string) $value->toScale(2) : (string) $value;
    }

    /**
     * Human display: "$12.30" for amounts of a cent or more, "$0.000125" (up to 6 decimals) below.
     */
    public static function format(?int $nanos): string
    {
        if ($nanos === null) {
            return '—';
        }

        $decimals = $nanos === 0 || abs($nanos) >= 10 ** (self::SCALE - 2) ? 2 : 6;
        $value = BigDecimal::ofUnscaledValue($nanos, self::SCALE)->toScale($decimals, RoundingMode::HalfUp)->strippedOfTrailingZeros();
        $value = $value->getScale() < 2 ? $value->toScale(2) : $value;
        $sign = $value->isNegative() ? '-' : '';

        return $sign.'$'.$value->abs();
    }
}
