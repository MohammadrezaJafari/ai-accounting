<?php

namespace App\Filament\Resources\Packages\Tables;

use App\Filament\Support\Fields;
use App\Models\Package;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class PackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label('نام')->searchable()->description(fn (Package $record) => $record->description),
                Fields::usdColumn('price')->label('مبلغ')->sortable(),
                Fields::usdColumn('credit')->label('اعتبار')->sortable(),
                TextColumn::make('bonus')->label('هدیه')
                    ->state(fn (Package $record) => $record->credit > $record->price ? Money::format($record->credit - $record->price) : null)
                    ->placeholder('—')->color('success')->extraAttributes(['dir' => 'ltr']),
                ToggleColumn::make('is_active')->label('فعال'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
