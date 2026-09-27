<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('نام')->required()->maxLength(100),
                TextInput::make('sort_order')->label('ترتیب نمایش')->numeric()->default(0),
                Fields::usd('price')->label('مبلغ پرداختی مشتری')->required()->gt(0),
                Fields::usd('credit')->label('اعتبار شارژشده')->required()->gt(0)
                    ->helperText('اگر بیشتر از مبلغ پرداختی باشد، مابه‌التفاوت هدیه است.'),
                Textarea::make('description')->label('توضیح')->columnSpanFull(),
                Toggle::make('is_active')->label('فعال')->default(true),
            ]);
    }
}
