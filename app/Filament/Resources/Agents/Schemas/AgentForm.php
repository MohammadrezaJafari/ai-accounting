<?php

namespace App\Filament\Resources\Agents\Schemas;

use App\Filament\Support\Fields;
use App\Models\Agent;
use App\Models\AiModel;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\ConfigSchema;
use App\Support\AgentDriver;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A marketplace listing: how it is presented, where the agent service runs, what it may
 * spend and the parameters customers fill in.
 */
class AgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معرفی در بازارچه')->columns(2)->columnSpanFull()->schema([
                    TextInput::make('name')->label('نام')->required()->maxLength(100),
                    TextInput::make('slug')->label('شناسه')->required()->maxLength(50)->unique(ignoreRecord: true)->disabledOn('edit')
                        ->regex('/^[a-z0-9][a-z0-9-]*$/')->extraInputAttributes(['dir' => 'ltr'])
                        ->helperText('در درخواست‌ها به سرویس ایجنت فرستاده می‌شود (X-Agent-Slug).'),
                    TextInput::make('tagline')->label('معرفی یک‌خطی')->maxLength(255)->columnSpanFull(),
                    Textarea::make('description')->label('توضیح کامل')->rows(5)->columnSpanFull()
                        ->helperText('Markdown؛ در صفحهٔ ایجنت در بازارچه نمایش داده می‌شود.'),
                    TextInput::make('category')->label('دسته')->maxLength(50)->placeholder('پایش و گزارش')
                        ->datalist(fn () => Agent::query()->whereNotNull('category')->distinct()->pluck('category')->all()),
                    TextInput::make('icon')->label('آیکون')->maxLength(50)->placeholder('smart_toy')->extraInputAttributes(['dir' => 'ltr'])
                        ->helperText('نام یک آیکون Material Icons، مثل feed، query_stats یا support_agent.'),
                    TextInput::make('publisher_name')->label('ناشر')->maxLength(255)->placeholder('خالی = خود پلتفرم'),
                    TextInput::make('publisher_url')->label('وب‌سایت ناشر')->url()->maxLength(500)->extraInputAttributes(['dir' => 'ltr']),
                    TextInput::make('sort_order')->label('ترتیب نمایش')->numeric()->default(0),
                    Toggle::make('is_active')->label('نمایش در بازارچه')->default(true)->inline(false),
                ]),

                Section::make('اتصال')->columns(2)->columnSpanFull()
                    ->description('ایجنت خارجی یک سرویس HTTP است که درخواست امضاشدهٔ هر اجرا را می‌گیرد و نتیجه را برمی‌گرداند؛ پروتکل در README آمده است.')
                    ->schema([
                        Select::make('driver')->label('نوع')->options(AgentDriver::options())->default(AgentDriver::Http->value)->required()->live()
                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                if ($value === AgentDriver::Builtin->value && ! in_array($get('slug'), AgentRegistry::builtinSlugs(), true)) {
                                    $fail('برای این شناسه پیاده‌سازی داخلی وجود ندارد.');
                                }
                            }),
                        TextInput::make('endpoint_url')->label('آدرس سرویس')->url()->maxLength(500)->extraInputAttributes(['dir' => 'ltr'])
                            ->placeholder('https://agent.example.com/run')
                            ->required(fn (Get $get) => self::isHttp($get))->visible(fn (Get $get) => self::isHttp($get)),
                        TextEntry::make('signing_secret')->label('کلید امضا')
                            ->state(fn (?Agent $record) => $record?->signing_secret ? Str::mask($record->signing_secret, '•', 8) : 'پس از ذخیره ساخته می‌شود.')
                            ->copyable(fn (?Agent $record) => filled($record?->signing_secret))
                            ->copyableState(fn (?Agent $record) => $record?->signing_secret)
                            ->helperText('سرویس با این کلید امضای X-Agent-Signature را بررسی می‌کند. آن را فقط به توسعه‌دهندهٔ ایجنت بدهید.')
                            ->extraAttributes(['dir' => 'ltr'])
                            ->visible(fn (Get $get) => self::isHttp($get)),
                        TextInput::make('timeout_seconds')->label('مهلت پاسخ مستقیم')->numeric()->minValue(5)->maxValue(300)->default(60)->suffix('ثانیه')
                            ->visible(fn (Get $get) => self::isHttp($get)),
                        TextInput::make('run_deadline_minutes')->label('مهلت ارسال نتیجه')->numeric()->minValue(1)->maxValue(720)->default(15)->suffix('دقیقه')
                            ->helperText('برای ایجنت‌هایی که با 202 کار را می‌پذیرند و نتیجه را بعداً می‌فرستند.')
                            ->visible(fn (Get $get) => self::isHttp($get)),
                    ]),

                Section::make('فروش و هزینه')->columns(3)->columnSpanFull()->schema([
                    TextInput::make('unit_name')->label('نام واحد فروش')->required()->maxLength(50)->placeholder('گزارش'),
                    TextInput::make('max_units_per_run')->label('حداکثر واحد در هر اجرا')->numeric()->minValue(1)->maxValue(1000)->default(1)->required()
                        ->helperText('ایجنت می‌تواند بگوید خروجی‌اش چند واحد می‌ارزد (مثلاً به ازای هر مورد پیدا شده)، تا این سقف.'),
                    TextInput::make('revenue_share')->label('سهم ناشر')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('٪')
                        ->helperText('درصدی از درآمد که به ناشر ایجنت بدهکاریم.'),
                    Select::make('model')->label('مدل پیش‌فرض')->searchable()
                        ->options(fn () => AiModel::query()->orderBy('public_id')->pluck('public_id', 'public_id'))
                        ->helperText('مدلی که ایجنت بدون تعیین model صدا می‌زند.'),
                    Select::make('allowed_models')->label('مدل‌های مجاز')->multiple()->searchable()
                        ->options(fn () => AiModel::query()->orderBy('public_id')->pluck('public_id', 'public_id'))
                        ->placeholder('همهٔ مدل‌های فعال'),
                    Fields::usd('max_cost_per_run')->label('سقف هزینهٔ مدل در هر اجرا')->minValue(0)->placeholder('بدون سقف')
                        ->helperText('با رسیدن هزینه به این مبلغ، درخواست‌های بعدی ایجنت به مدل رد می‌شوند.'),
                ]),

                Section::make('پارامترهای مشتری')->columnSpanFull()
                    ->description('فرمی که مشتری هنگام ساخت ایجنت پر می‌کند؛ مقدارها در config هر اجرا به سرویس فرستاده می‌شوند.')
                    ->schema([
                        Repeater::make('config_schema')->hiddenLabel()->columns(4)->collapsible()->collapsed()->reorderable()
                            ->addActionLabel('افزودن پارامتر')
                            ->itemLabel(fn (array $state) => trim(($state['label'] ?? '').' ('.($state['key'] ?? '?').' — '.(ConfigSchema::TYPES[$state['type'] ?? ''] ?? '').')'))
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                                try {
                                    ConfigSchema::define(array_values($value ?? []));
                                } catch (InvalidArgumentException $e) {
                                    $fail($e->getMessage());
                                }
                            })
                            ->dehydrateStateUsing(fn (?array $state) => ConfigSchema::define(array_values($state ?? [])))
                            ->schema([
                                TextInput::make('key')->label('شناسه')->required()->maxLength(40)->regex('/^[a-z][a-z0-9_]*$/')->extraInputAttributes(['dir' => 'ltr']),
                                TextInput::make('label')->label('عنوان')->required()->maxLength(100),
                                Select::make('type')->label('نوع')->options(ConfigSchema::TYPES)->default('text')->required()->live(),
                                TextInput::make('section')->label('بخش فرم')->maxLength(50)->placeholder('مثلاً فیلترها'),
                                Toggle::make('required')->label('اجباری')->inline(false),
                                TextInput::make('hint')->label('راهنما')->maxLength(255)->columnSpan(2),
                                TextInput::make('placeholder')->label('نمونه')->maxLength(255),
                                TextInput::make('default')->label('پیش‌فرض')->maxLength(500)
                                    ->formatStateUsing(fn (mixed $state) => match (true) {
                                        is_array($state) => implode(', ', $state),
                                        is_bool($state) => $state ? 'true' : 'false',
                                        default => $state,
                                    })
                                    ->helperText(fn (Get $get) => match ($get('type')) {
                                        'toggle' => 'true یا false',
                                        'tags', 'url_list', 'multiselect' => 'با کاما جدا کنید',
                                        default => null,
                                    })
                                    ->hidden(fn (Get $get) => $get('type') === 'secret'),
                                TextInput::make('min')->label('حداقل')->numeric()->visible(fn (Get $get) => $get('type') === 'number'),
                                TextInput::make('max')->label('حداکثر / طول')->numeric()->visible(fn (Get $get) => in_array($get('type'), ['number', 'text', 'textarea'], true)),
                                TextInput::make('max_items')->label('حداکثر تعداد')->numeric()->minValue(1)
                                    ->visible(fn (Get $get) => in_array($get('type'), ['tags', 'url_list', 'multiselect'], true)),
                                Repeater::make('options')->label('گزینه‌ها')->columns(2)->columnSpanFull()->addActionLabel('افزودن گزینه')
                                    ->visible(fn (Get $get) => in_array($get('type'), ['select', 'multiselect'], true))
                                    ->schema([
                                        TextInput::make('value')->label('مقدار')->required()->maxLength(100)->extraInputAttributes(['dir' => 'ltr']),
                                        TextInput::make('label')->label('عنوان')->required()->maxLength(100),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    private static function isHttp(Get $get): bool
    {
        $driver = $get('driver');

        return ($driver instanceof AgentDriver ? $driver->value : $driver) === AgentDriver::Http->value;
    }
}
