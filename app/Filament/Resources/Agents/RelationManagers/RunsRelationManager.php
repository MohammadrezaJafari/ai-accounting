<?php

namespace App\Filament\Resources\Agents\RelationManagers;

use App\Models\AgentRun;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Every run with its revenue, model cost and margin.
 */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'اجراها';

    public const STATUSES = [
        AgentRun::STATUS_QUEUED => 'در صف',
        AgentRun::STATUS_RUNNING => 'در حال اجرا',
        AgentRun::STATUS_SUCCEEDED => 'گزارش داد',
        AgentRun::STATUS_EMPTY => 'خبر تازه نبود',
        AgentRun::STATUS_NO_CREDITS => 'بدون اعتبار',
        AgentRun::STATUS_FAILED => 'ناموفق',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['organization', 'instance']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('زمان')->jalaliDateTime(),
                TextColumn::make('organization.name')->label('سازمان')->searchable(),
                TextColumn::make('instance.name')->label('ایجنت مشتری'),
                TextColumn::make('status')->label('وضعیت')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        AgentRun::STATUS_SUCCEEDED => 'success',
                        AgentRun::STATUS_FAILED, AgentRun::STATUS_NO_CREDITS => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (AgentRun $record) => $record->error),
                TextColumn::make('items_found')->label('خبر'),
                TextColumn::make('revenue')->label('درآمد')->formatStateUsing(fn ($state) => Money::format((int) $state))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('cost')->label('هزینه')->formatStateUsing(fn ($state) => Money::format((int) $state))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('margin')->label('سود')->state(fn (AgentRun $record) => Money::format($record->margin()))
                    ->color(fn (AgentRun $record) => $record->margin() < 0 ? 'danger' : null)->extraAttributes(['dir' => 'ltr']),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options(self::STATUSES),
            ])
            ->recordActions([
                Action::make('report')->label('گزارش')->icon('heroicon-o-document-text')
                    ->visible(fn (AgentRun $record) => filled($record->report))
                    ->modalHeading(fn (AgentRun $record) => $record->instance->name)
                    ->modalSubmitAction(false)
                    ->modalContent(fn (AgentRun $record) => new HtmlString(
                        '<div class="prose dark:prose-invert max-w-none">'.Str::markdown($record->report ?? '', ['html_input' => 'escape', 'allow_unsafe_links' => false]).'</div>'
                    )),
            ]);
    }
}
