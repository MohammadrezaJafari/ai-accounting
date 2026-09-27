<?php

namespace App\Filament\Pages;

use App\Filament\Support\Fields;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * تنظیمات قیمت‌گذاری و شارژ که بدون deploy قابل تغییرند.
 *
 * @property-read Schema $form
 */
class BillingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'سیستم';

    protected static ?string $navigationLabel = 'تنظیمات مالی';

    protected static ?string $title = 'تنظیمات مالی';

    protected static ?string $slug = 'billing-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(SettingsService $settings): void
    {
        $this->form->fill($settings->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('قیمت‌گذاری')->columns(2)->schema([
                    Fields::percent('default_markup_bps')->label('درصد سود پیش‌فرض')->required()
                        ->helperText('روی قیمت خرید همهٔ مدل‌هایی اعمال می‌شود که مدل، ارائه‌دهنده یا اپ‌شان درصد جداگانه ندارد.'),
                    Fields::usd('min_balance')->label('حداقل موجودی برای ارسال درخواست')->required()
                        ->helperText('وقتی موجودی اپ به این مقدار یا کمتر برسد، gateway خطای 402 می‌دهد.'),
                ]),
                Section::make('شارژ با مبلغ دلخواه')->columns(3)->schema([
                    Toggle::make('custom_topup_enabled')->label('فعال')->inline(false),
                    Fields::usd('custom_topup_min')->label('حداقل مبلغ')->required()->minValue(0),
                    Fields::usd('custom_topup_max')->label('حداکثر مبلغ')->required()->minValue(0),
                ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('ذخیره')->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(SettingsService $settings): void
    {
        $settings->update($this->form->getState());

        Notification::make()->success()->title('تنظیمات ذخیره شد.')->send();
    }
}
