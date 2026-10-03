<?php

namespace App\Filament\Resources\Apps\RelationManagers;

use App\Filament\Support\Fields;
use App\Models\WalletTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'تراکنش‌های کیف پول';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('زمان')->jalaliDateTime(),
                TextColumn::make('type')->label('نوع')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    WalletTransaction::TYPE_TOPUP => 'شارژ',
                    WalletTransaction::TYPE_REFUND => 'بازپرداخت',
                    WalletTransaction::TYPE_PUBLISHER_DEPOSIT => 'جبران بدهی ناشر',
                    default => 'اصلاح دستی',
                }),
                Fields::usdColumn('amount')->label('مبلغ')->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
                Fields::usdColumn('balance_after')->label('موجودی پس از'),
                TextColumn::make('description')->label('توضیح')->placeholder('—'),
                TextColumn::make('creator.name')->label('توسط')->placeholder('سیستم'),
            ]);
    }
}
