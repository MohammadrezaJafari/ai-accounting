<?php

namespace App\Filament\Resources\Agents\Schemas;

use App\Filament\Support\Fields;
use App\Models\AiModel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('نام')->required()->maxLength(100),
                TextInput::make('slug')->label('شناسه')->required()->maxLength(50)->disabledOn('edit')
                    ->helperText('پیاده‌سازی ایجنت با این شناسه انتخاب می‌شود (news-monitor).')
                    ->extraInputAttributes(['dir' => 'ltr']),
                Textarea::make('description')->label('توضیح برای مشتری')->columnSpanFull(),
                TextInput::make('unit_name')->label('نام واحد فروش')->required()->maxLength(50)->placeholder('گزارش'),
                Select::make('model')->label('مدل هوش مصنوعی')->required()->searchable()
                    ->options(fn () => AiModel::query()->orderBy('public_id')->pluck('public_id', 'public_id')),
                Fields::usd('max_cost_per_run')->label('سقف هزینهٔ هر اجرا')->minValue(0)->placeholder('بدون سقف')
                    ->helperText('اگر هزینهٔ مدل در یک اجرا به این مبلغ برسد، اجرا متوقف می‌شود تا واحد زیان‌ده فروخته نشود.'),
                TextInput::make('sort_order')->label('ترتیب نمایش')->numeric()->default(0),
                Toggle::make('is_active')->label('فعال')->default(true),
            ]);
    }
}
