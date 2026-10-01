<?php

namespace App\Support;

/**
 * What kind of work an agent does, which decides where its output lives. A report agent runs on
 * a schedule and delivers a document; an interactive one is talked to (in the Rahap messenger)
 * and the marketplace only sells and meters it.
 */
enum AgentKind: string
{
    case Report = 'report';
    case Interactive = 'interactive';

    public function label(): string
    {
        return match ($this) {
            self::Report => 'گزارش زمان‌بندی‌شده',
            self::Interactive => 'تعاملی (گفتگویی)',
        };
    }
}
