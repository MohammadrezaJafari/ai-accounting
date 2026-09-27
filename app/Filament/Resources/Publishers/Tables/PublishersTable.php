<?php

namespace App\Filament\Resources\Publishers\Tables;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Support\Fields;
use App\Models\Organization;
use App\Models\PublisherPayout;
use App\Services\Publishers\PublisherEarnings;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PublishersTable
{
    public static function configure(Table $table): Table
    {
        $summary = fn (Organization $record) => app(PublisherEarnings::class)->summary($record);

        return $table
            ->recordUrl(fn (Organization $record) => OrganizationResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('سازمان')->searchable()
                    ->description(fn (Organization $record) => $record->publisher_name),
                TextColumn::make('published_agents_count')->label('ایجنت‌ها'),
                TextColumn::make('share')->label('سهم فروش')->state(fn (Organization $record) => Money::format($summary($record)['share']))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('model_cost')->label('هزینهٔ مدل')->state(fn (Organization $record) => Money::format($summary($record)['model_cost']))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('earned')->label('درآمد خالص')->state(fn (Organization $record) => Money::format($summary($record)['earned']))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('paid')->label('پرداخت‌شده')->state(fn (Organization $record) => Money::format($summary($record)['paid']))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('balance')->label('مانده')
                    ->state(fn (Organization $record) => Money::format($summary($record)['balance']))
                    ->color(fn (Organization $record) => $summary($record)['balance'] > 0 ? 'warning' : null)
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('support_email')->label('ایمیل پشتیبانی')->extraAttributes(['dir' => 'ltr'])->toggleable(),
            ])
            ->recordActions([
                Action::make('recordPayout')->label('ثبت تسویه')->icon('heroicon-o-banknotes')
                    ->schema(fn (Organization $record) => [
                        TextEntry::make('payout_details')->label('اطلاعات پرداخت ناشر')
                            ->state($record->payout_details ?: 'ناشر اطلاعات پرداخت وارد نکرده است.'),
                        Fields::usd('amount')->label('مبلغ')->required()->minValue(0.01)
                            ->default(Money::toUsd(max(0, $summary($record)['balance']))),
                        DateTimePicker::make('paid_at')->label('تاریخ پرداخت')->required()->default(now())->jalali(),
                        TextInput::make('reference')->label('شمارهٔ پیگیری')->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
                        TextInput::make('note')->label('توضیح')->maxLength(500),
                    ])
                    ->action(function (array $data, Organization $record) {
                        PublisherPayout::query()->create([...$data, 'organization_id' => $record->id, 'created_by' => auth()->id()]);
                        Notification::make()->title('تسویه ثبت شد.')->success()->send();
                    }),
            ]);
    }
}
