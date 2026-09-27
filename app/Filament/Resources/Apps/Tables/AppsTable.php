<?php

namespace App\Filament\Resources\Apps\Tables;

use App\Filament\Resources\Apps\AdjustBalanceAction;
use App\Filament\Support\Fields;
use App\Models\App;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AppsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('organization')->withCount('apiKeys'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('اپ')->searchable()->sortable(),
                TextColumn::make('organization.name')->label('سازمان')->searchable(),
                Fields::usdColumn('balance')->label('موجودی')->sortable()
                    ->color(fn (App $record) => $record->balance <= 0 ? 'danger' : null),
                TextColumn::make('spend_limit')->label('مصرف / سقف دوره')->placeholder('بدون سقف')
                    ->formatStateUsing(fn (App $record) => Money::format($record->spentThisPeriod()).' / '.Money::format($record->spend_limit).' '.$record->spend_limit_period->label())
                    ->color(fn (App $record) => $record->isOverSpendLimit() ? 'danger' : null)
                    ->extraAttributes(['dir' => 'ltr']),
                Fields::percentColumn('markup_bps')->label('سود اختصاصی')->placeholder('—'),
                TextColumn::make('api_keys_count')->label('کلیدها'),
                ToggleColumn::make('is_active')->label('فعال'),
                TextColumn::make('created_at')->label('ایجاد')->jalaliDateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('فعال'),
                Filter::make('no_balance')->label('بدون موجودی')->query(fn ($query) => $query->where('balance', '<=', 0)),
            ])
            ->recordActions([
                AdjustBalanceAction::make(),
                EditAction::make(),
            ]);
    }
}
