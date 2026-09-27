<?php

namespace App\Filament\Support;

use App\Support\Money;
use App\Support\Percent;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

/**
 * Money is stored in nano-USD and markups in basis points; these fields let the
 * operator work in dollars and percentages.
 */
final class Fields
{
    public static function usd(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->prefix('$')
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::toUsd((int) $state))
            ->dehydrateStateUsing(fn ($state) => Money::fromUsd($state));
    }

    public static function percent(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->suffix('٪')
            ->minValue(-100)
            ->formatStateUsing(fn ($state) => Percent::fromBps($state === null || $state === '' ? null : (int) $state))
            ->dehydrateStateUsing(fn ($state) => Percent::toBps($state));
    }

    public static function usdColumn(string $name): TextColumn
    {
        return TextColumn::make($name)
            ->formatStateUsing(fn ($state) => Money::format($state === null ? null : (int) $state))
            ->extraAttributes(['dir' => 'ltr'])
            ->alignEnd();
    }

    public static function percentColumn(string $name): TextColumn
    {
        return TextColumn::make($name)
            ->formatStateUsing(fn ($state) => $state === null ? null : Percent::fromBps((int) $state).'٪');
    }
}
