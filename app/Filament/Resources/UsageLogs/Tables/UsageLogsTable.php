<?php

namespace App\Filament\Resources\UsageLogs\Tables;

use App\Filament\Support\Fields;
use App\Models\UsageLog;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsageLogsTable
{
    public static function configure(Table $table): Table
    {
        $sum = fn (string $label) => Sum::make()->label($label)->formatStateUsing(fn ($state) => Money::format((int) $state));

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['app', 'apiKey', 'provider']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('زمان')->jalaliDateTime()->sortable(),
                TextColumn::make('app.name')->label('اپ')->searchable()
                    ->description(fn (UsageLog $record) => $record->apiKey?->name),
                TextColumn::make('model')->label('مدل')->searchable()->badge()->color('gray'),
                TextColumn::make('endpoint')->label('endpoint')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('input_tokens')->label('ورودی')->numeric()
                    ->description(fn (UsageLog $record) => $record->cached_input_tokens ? 'کش: '.number_format($record->cached_input_tokens) : null),
                TextColumn::make('output_tokens')->label('خروجی')->numeric(),
                Fields::usdColumn('cost')->label('هزینه')->summarize($sum('جمع هزینه')),
                Fields::usdColumn('charge')->label('دریافتی')->summarize($sum('جمع دریافتی')),
                TextColumn::make('profit')->label('سود')
                    ->state(fn (UsageLog $record) => Money::format($record->charge - $record->cost))
                    ->color('success')->extraAttributes(['dir' => 'ltr'])->alignEnd(),
                TextColumn::make('status_code')->label('وضعیت')->badge()
                    ->color(fn (int $state) => $state >= 400 ? 'danger' : 'success')
                    ->tooltip(fn (UsageLog $record) => $record->error),
                TextColumn::make('latency_ms')->label('زمان پاسخ')->suffix(' ms')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('app_id')->label('اپ')->relationship('app', 'name')->searchable(),
                SelectFilter::make('provider_id')->label('ارائه‌دهنده')->relationship('provider', 'name'),
                SelectFilter::make('model')->label('مدل')->options(fn () => UsageLog::query()->distinct()->orderBy('model')->pluck('model', 'model')),
                Filter::make('errors')->label('فقط خطاها')->query(fn (Builder $query) => $query->where('status_code', '>=', 400)),
                Filter::make('created_at')->label('بازهٔ زمانی')
                    ->schema([
                        DatePicker::make('from')->label('از')->jalali(),
                        DatePicker::make('until')->label('تا')->jalali(),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ]);
    }
}
