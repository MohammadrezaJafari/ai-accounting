<?php

namespace App\Filament\Resources\Providers\Schemas;

use App\Filament\Support\Fields;
use App\Models\Provider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('نام')->required()->maxLength(100),
                TextInput::make('slug')->label('شناسه')->required()->alphaDash()->maxLength(50)->unique(ignoreRecord: true)
                    ->helperText('مثل openai، anthropic، google. در محدودسازی کلیدهای اپ استفاده می‌شود.'),
                TextInput::make('base_url')->label('آدرس API سازگار با OpenAI')->required()->url()->columnSpanFull()
                    ->helperText('درخواست‌های /v1/chat/completions به {base_url}/chat/completions فرستاده می‌شوند.'),
                Select::make('native_format')->label('API اختصاصی')->options([Provider::NATIVE_ANTHROPIC => 'Anthropic Messages (/v1/messages)'])
                    ->placeholder('ندارد')->live(),
                TextInput::make('native_base_url')->label('آدرس API اختصاصی')->url()
                    ->required(fn (Get $get) => filled($get('native_format')))
                    ->visible(fn (Get $get) => filled($get('native_format'))),
                Fields::percent('markup_bps')->label('درصد سود پیش‌فرض این ارائه‌دهنده')
                    ->helperText('خالی = درصد سود عمومی از تنظیمات. مدل یا اپ می‌توانند آن را بازنویسی کنند.'),
                Toggle::make('is_active')->label('فعال')->default(true),
            ]);
    }
}
