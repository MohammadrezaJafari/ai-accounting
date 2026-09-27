<?php

namespace App\Filament\Resources\Agents\Tables;

use App\Models\Agent;
use App\Services\Agents\AgentStats;
use App\Support\AgentDriver;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Marketplace agents with what their units earn, cost and owe their publisher, to check that
 * unit prices keep a margin.
 */
class AgentsTable
{
    public static function configure(Table $table): Table
    {
        $stats = fn (Agent $record) => app(AgentStats::class)->for($record);

        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('ایجنت')->searchable()
                    ->description(fn (Agent $record) => $record->publisher_name ? "ناشر: {$record->publisher_name}" : $record->category),
                TextColumn::make('driver')->label('نوع')->badge()
                    ->formatStateUsing(fn (AgentDriver $state) => $state === AgentDriver::Http ? 'HTTP' : 'داخلی')
                    ->color(fn (AgentDriver $state) => $state === AgentDriver::Http ? 'info' : 'gray')
                    ->tooltip(fn (Agent $record) => $record->endpoint_url),
                TextColumn::make('reports')->label('واحد فروخته‌شده')->state(fn (Agent $record) => $stats($record)['reports']),
                TextColumn::make('revenue')->label('درآمد')->state(fn (Agent $record) => Money::format($stats($record)['revenue']))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('cost')->label('هزینهٔ مدل')->state(fn (Agent $record) => Money::format($stats($record)['cost']))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('publisher_share')->label('سهم ناشر')
                    ->state(fn (Agent $record) => $record->revenue_share > 0 ? Money::format($stats($record)['publisher_share'])." ({$record->revenue_share}٪)" : '—')
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('margin')->label('سود')
                    ->state(fn (Agent $record) => Money::format($stats($record)['margin']).($stats($record)['margin_percent'] !== null ? " ({$stats($record)['margin_percent']}٪)" : ''))
                    ->color(fn (Agent $record) => $stats($record)['margin'] < 0 ? 'danger' : 'success')
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('avg_cost')->label('میانگین هزینهٔ هر واحد')
                    ->state(fn (Agent $record) => Money::format($stats($record)['avg_cost']))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('p95_cost')->label('هزینهٔ ۹۵٪ واحدها کمتر از')
                    ->state(fn (Agent $record) => Money::format($stats($record)['p95_cost']))->extraAttributes(['dir' => 'ltr']),
                ToggleColumn::make('is_active')->label('فعال'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
