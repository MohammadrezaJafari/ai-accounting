<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Support\Fields;
use App\Models\Order;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public const STATUSES = [
        Order::STATUS_PENDING => 'در انتظار پرداخت',
        Order::STATUS_PAID => 'پرداخت‌شده',
        Order::STATUS_CANCELLED => 'لغوشده',
        Order::STATUS_FAILED => 'ناموفق',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'app', 'package']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.email')->label('کاربر')->searchable(),
                TextColumn::make('app.name')->label('اپ')->searchable(),
                TextColumn::make('type')->label('نوع')
                    ->formatStateUsing(fn (Order $record) => $record->type === Order::TYPE_PACKAGE ? 'بسته: '.($record->meta['package_name'] ?? '') : 'مبلغ دلخواه'),
                Fields::usdColumn('amount')->label('مبلغ')->sortable(),
                Fields::usdColumn('credit')->label('اعتبار'),
                TextColumn::make('status')->label('وضعیت')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Order::STATUS_PAID => 'success',
                        Order::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('gateway')->label('درگاه')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gateway_ref')->label('شناسهٔ پرداخت')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('ثبت')->jalaliDateTime()->sortable(),
                TextColumn::make('paid_at')->label('پرداخت')->jalaliDateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options(self::STATUSES),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label('تأیید پرداخت')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Order $record) => $record->status === Order::STATUS_PENDING)
                    ->schema([
                        TextInput::make('gateway_ref')->label('شناسهٔ پرداخت / شمارهٔ پیگیری')->maxLength(255),
                    ])
                    ->modalDescription('اعتبار سفارش به موجودی اپ اضافه می‌شود.')
                    ->action(function (Order $record, array $data) {
                        app(OrderService::class)->markPaid($record, $data['gateway_ref'] ?? null, auth()->user());
                        Notification::make()->success()->title('سفارش پرداخت‌شده ثبت شد و اپ شارژ شد.')->send();
                    }),
                Action::make('cancel')
                    ->label('لغو')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Order $record) => $record->status === Order::STATUS_PENDING)
                    ->action(fn (Order $record) => app(OrderService::class)->cancel($record)),
            ]);
    }
}
