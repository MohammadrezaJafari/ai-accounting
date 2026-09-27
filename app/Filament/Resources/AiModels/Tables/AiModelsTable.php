<?php

namespace App\Filament\Resources\AiModels\Tables;

use App\Filament\Support\Fields;
use App\Models\AiModel;
use App\Services\PricingService;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AiModelsTable
{
    public static function configure(Table $table): Table
    {
        $sell = fn (AiModel $record, string $category) => Money::format(app(PricingService::class)->sellPrices($record)[$category]);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('provider'))
            ->defaultSort('provider_id')
            ->columns([
                TextColumn::make('name')->label('مدل')->searchable()->sortable()
                    ->description(fn (AiModel $record) => $record->public_id),
                TextColumn::make('provider.name')->label('ارائه‌دهنده')->badge()->color('gray')->sortable(),
                Fields::usdColumn('input_price')->label('خرید ورودی')->sortable(),
                Fields::usdColumn('output_price')->label('خرید خروجی')->sortable(),
                TextColumn::make('sell_input')->label('فروش ورودی')->state(fn (AiModel $record) => $sell($record, 'input'))
                    ->color('success')->extraAttributes(['dir' => 'ltr'])->alignEnd(),
                TextColumn::make('sell_output')->label('فروش خروجی')->state(fn (AiModel $record) => $sell($record, 'output'))
                    ->color('success')->extraAttributes(['dir' => 'ltr'])->alignEnd(),
                TextColumn::make('markup')->label('سود')
                    ->state(fn (AiModel $record) => (app(PricingService::class)->markupBps($record) / 100).'٪')
                    ->description(fn (AiModel $record) => $record->markup_bps === null ? 'ارثی' : 'اختصاصی'),
                TextColumn::make('context_window')->label('زمینه')->numeric()->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')->label('فعال'),
            ])
            ->filters([
                SelectFilter::make('provider_id')->label('ارائه‌دهنده')->relationship('provider', 'name'),
                TernaryFilter::make('is_active')->label('فعال'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
