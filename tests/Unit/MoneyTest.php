<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{0: ?int, 1: string}>
     */
    public static function amounts(): array
    {
        return [
            'dollars' => [Money::fromUsd('12.3'), '$12.30'],
            'cents keep two decimals' => [Money::fromUsd('0.232379'), '$0.23'],
            'exactly one cent' => [Money::fromUsd('0.01'), '$0.01'],
            'sub-cent keeps precision' => [Money::fromUsd('0.000125'), '$0.000125'],
            'zero' => [0, '$0.00'],
            'negative' => [Money::fromUsd('-1.5'), '-$1.50'],
            'missing' => [null, '—'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_format_uses_two_decimals_from_a_cent_up(?int $nanos, string $expected): void
    {
        $this->assertSame($expected, Money::format($nanos));
    }
}
