<?php

namespace App\Filament\Resources\Apps\Schemas;

use App\Filament\Support\Fields;
use App\Models\App;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AppForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')->label('مالک')->relationship('user', 'email')->searchable()->preload()->required(),
                TextInput::make('name')->label('نام اپ')->required()->maxLength(100),
                Textarea::make('description')->label('توضیح')->columnSpanFull(),
                Fields::percent('markup_bps')->label('درصد سود اختصاصی (قرارداد ویژه)')
                    ->helperText('اگر پر شود، برای همهٔ مدل‌ها قیمت = قیمت خرید + این درصد. خالی = قیمت‌گذاری عادی.'),
                TextEntry::make('balance_display')->label('موجودی')
                    ->state(fn (?App $record) => $record ? Money::format($record->balance) : '$0.00')
                    ->helperText('برای تغییر موجودی از دکمهٔ «تنظیم موجودی» استفاده کنید.'),
                Toggle::make('is_active')->label('فعال')->default(true)
                    ->helperText('اپ غیرفعال هیچ درخواستی را از gateway عبور نمی‌دهد.'),
            ]);
    }
}
