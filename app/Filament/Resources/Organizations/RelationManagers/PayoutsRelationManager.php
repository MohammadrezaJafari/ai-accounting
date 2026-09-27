<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Filament\Support\Fields;
use App\Services\Publishers\PublisherEarnings;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Payouts to the organization as a marketplace publisher.
 */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'تسویه با ناشر';

    protected static ?string $modelLabel = 'تسویه';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->publishedAgents()->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::usd('amount')->label('مبلغ')->required()->minValue(0.01)
                ->default(fn () => Money::toUsd(max(0, app(PublisherEarnings::class)->summary($this->getOwnerRecord())['balance']))),
            DateTimePicker::make('paid_at')->label('تاریخ پرداخت')->required()->default(now())->jalali(),
            TextInput::make('reference')->label('شمارهٔ پیگیری')->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('note')->label('توضیح')->maxLength(500),
        ]);
    }

    public function table(Table $table): Table
    {
        $summary = fn () => app(PublisherEarnings::class)->summary($this->getOwnerRecord());

        return $table
            ->defaultSort('paid_at', 'desc')
            ->description(fn () => sprintf(
                'سهم فروش: %s — هزینهٔ مدل: %s — درآمد خالص: %s — پرداخت‌شده: %s — مانده: %s',
                Money::format($summary()['share']),
                Money::format($summary()['model_cost']),
                Money::format($summary()['earned']),
                Money::format($summary()['paid']),
                Money::format($summary()['balance']),
            ))
            ->columns([
                TextColumn::make('paid_at')->label('تاریخ')->jalaliDateTime(),
                Fields::usdColumn('amount')->label('مبلغ'),
                TextColumn::make('reference')->label('شمارهٔ پیگیری')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('note')->label('توضیح'),
                TextColumn::make('creator.name')->label('ثبت‌کننده'),
            ])
            ->headerActions([
                CreateAction::make()->label('ثبت تسویه')
                    ->mutateDataUsing(fn (array $data) => [...$data, 'created_by' => auth()->id()]),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}
