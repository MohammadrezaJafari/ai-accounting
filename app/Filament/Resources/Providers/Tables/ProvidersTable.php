<?php

namespace App\Filament\Resources\Providers\Tables;

use App\Filament\Support\Fields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['keys', 'models']))
            ->columns([
                TextColumn::make('name')->label('نام')->searchable()->sortable(),
                TextColumn::make('slug')->label('شناسه')->badge()->color('gray'),
                TextColumn::make('base_url')->label('آدرس')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('native_format')->label('API اختصاصی')->placeholder('—'),
                Fields::percentColumn('markup_bps')->label('درصد سود')->placeholder('پیش‌فرض'),
                TextColumn::make('keys_count')->label('کلیدها')->badge()->color(fn (int $state) => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('models_count')->label('مدل‌ها'),
                ToggleColumn::make('is_active')->label('فعال'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
