<?php

namespace App\Filament\Resources\Apps;

use App\Filament\Support\Fields;
use App\Models\App;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * شارژ یا کسر دستی موجودی (هدیه، بازپرداخت، پرداخت آفلاین).
 */
class AdjustBalanceAction
{
    public static function make(): Action
    {
        return Action::make('adjustBalance')
            ->label('تنظیم موجودی')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->schema([
                Fields::usd('amount')->label('مبلغ')->required()->notIn([0])
                    ->helperText('مثبت = افزایش موجودی، منفی = کسر.'),
                Select::make('type')->label('نوع')->required()->default(WalletTransaction::TYPE_ADJUSTMENT)->options([
                    WalletTransaction::TYPE_TOPUP => 'شارژ',
                    WalletTransaction::TYPE_ADJUSTMENT => 'اصلاح دستی',
                    WalletTransaction::TYPE_REFUND => 'بازپرداخت',
                ]),
                TextInput::make('description')->label('توضیح')->maxLength(255),
            ])
            ->action(function (App $record, array $data) {
                $transaction = app(WalletService::class)->adjust(
                    $record,
                    (int) $data['amount'],
                    $data['type'],
                    $data['description'] ?? null,
                    by: auth()->user(),
                );

                Notification::make()->success()
                    ->title('موجودی به‌روز شد')
                    ->body('موجودی جدید: '.Money::format($transaction->balance_after))
                    ->send();
            });
    }
}
