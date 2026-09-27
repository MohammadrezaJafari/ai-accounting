<?php

namespace App\Filament\Resources\AiModels\Schemas;

use App\Filament\Support\Fields;
use App\Models\AiModel;
use App\Services\PricingService;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * همهٔ قیمت‌ها به دلار برای هر یک میلیون توکن.
 */
class AiModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('مشخصات')->columns(2)->columnSpanFull()->schema([
                    Select::make('provider_id')->label('ارائه‌دهنده')->relationship('provider', 'name')->required()->preload(),
                    TextInput::make('name')->label('نام نمایشی')->required()->maxLength(100),
                    TextInput::make('description')->label('توضیح کوتاه')->maxLength(255)->columnSpanFull()
                        ->helperText('زیر نام مدل در کارت‌های پنل مشتری نمایش داده می‌شود؛ مثل «استدلال و تحلیل پیشرفته».'),
                    TextInput::make('public_id')->label('شناسهٔ عمومی')->required()->maxLength(100)->unique(ignoreRecord: true)
                        ->regex('/^[A-Za-z0-9._:\/-]+$/')->extraInputAttributes(['dir' => 'ltr'])
                        ->helperText('نامی که اپ‌ها در فیلد model می‌فرستند.'),
                    TextInput::make('upstream_id')->label('شناسه نزد ارائه‌دهنده')->required()->maxLength(150)->extraInputAttributes(['dir' => 'ltr'])
                        ->helperText('نام واقعی مدل در API ارائه‌دهنده.'),
                    TextInput::make('context_window')->label('پنجرهٔ زمینه (توکن)')->numeric()->minValue(0),
                    Toggle::make('is_active')->label('فعال')->default(true)->inline(false),
                    Toggle::make('is_featured')->label('ویژه (نمایش در داشبورد مشتری)')->inline(false),
                ]),
                Section::make('قیمت خرید (هزینهٔ واقعی)')->description('دلار به ازای هر ۱ میلیون توکن، طبق قیمت رسمی ارائه‌دهنده.')
                    ->columns(4)->columnSpanFull()->schema([
                        Fields::usd('input_price')->label('ورودی')->required()->minValue(0),
                        Fields::usd('output_price')->label('خروجی')->required()->minValue(0),
                        Fields::usd('cached_input_price')->label('ورودی کش‌شده')->minValue(0)->placeholder('= ورودی'),
                        Fields::usd('cache_write_price')->label('نوشتن کش')->minValue(0)->placeholder('= ورودی'),
                    ]),
                Section::make('قیمت فروش')
                    ->description('پیش‌فرض: قیمت خرید + درصد سود. اگر قیمت ثابتی وارد کنید، همان به مشتری اعمال می‌شود (مگر اپ قرارداد اختصاصی داشته باشد).')
                    ->columns(4)->columnSpanFull()->schema([
                        Fields::percent('markup_bps')->label('درصد سود این مدل')->placeholder('پیش‌فرض ارائه‌دهنده/عمومی')->columnSpan(2),
                        TextEntry::make('effective_prices')->label('قیمت نهایی فعلی برای مشتری')->columnSpan(2)
                            ->state(fn (?AiModel $record) => $record ? self::summary($record) : 'پس از ذخیره محاسبه می‌شود.'),
                        Fields::usd('sell_input_price')->label('فروش ثابت ورودی')->minValue(0),
                        Fields::usd('sell_output_price')->label('فروش ثابت خروجی')->minValue(0),
                        Fields::usd('sell_cached_input_price')->label('فروش ثابت کش')->minValue(0),
                        Fields::usd('sell_cache_write_price')->label('فروش ثابت نوشتن کش')->minValue(0),
                    ]),
            ]);
    }

    private static function summary(AiModel $model): string
    {
        $pricing = app(PricingService::class);
        $sell = $pricing->sellPrices($model->loadMissing('provider'));

        return sprintf(
            'ورودی %s · خروجی %s · کش %s · سود %s٪',
            Money::format($sell['input']),
            Money::format($sell['output']),
            Money::format($sell['cached_input']),
            $pricing->markupBps($model) / 100,
        );
    }
}
