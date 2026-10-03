<?php

namespace App\Filament\Resources\Apps\RelationManagers;

use App\Filament\Support\Fields;
use App\Models\AiModel;
use App\Models\AppApiKey;
use App\Models\Provider;
use App\Services\ApiKeyService;
use App\Support\BudgetPeriod;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * کلیدهایی که اپ با آن‌ها gateway را صدا می‌زند؛ هر کلید می‌تواند به ارائه‌دهنده یا مدل‌های خاصی محدود شود.
 */
class ApiKeysRelationManager extends RelationManager
{
    protected static string $relationship = 'apiKeys';

    protected static ?string $title = 'کلیدهای API';

    protected static ?string $modelLabel = 'کلید API';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('نام')->required()->maxLength(100),
                Fields::usd('spend_limit')->label('سقف هزینه')->minValue(0)->placeholder('نامحدود'),
                Select::make('spend_limit_period')->label('دورهٔ سقف')->options(BudgetPeriod::options())
                    ->default(BudgetPeriod::Total->value)->selectablePlaceholder(false),
                Select::make('allowed_providers')->label('فقط این ارائه‌دهنده‌ها')->multiple()
                    ->options(fn () => Provider::query()->pluck('name', 'slug'))->placeholder('همه'),
                Select::make('allowed_models')->label('فقط این مدل‌ها')->multiple()->searchable()
                    ->options(fn () => AiModel::query()->orderBy('public_id')->pluck('public_id', 'public_id'))->placeholder('همه'),
                TextInput::make('rate_limit_per_minute')->label('سقف درخواست در دقیقه')->integer()->minValue(1)
                    ->placeholder(fn () => 'پیش‌فرض پلتفرم: '.(config('billing.gateway_rate_limit.per_minute') ?: 'نامحدود')),
                DateTimePicker::make('expires_at')->label('انقضا')->jalali(),
                Toggle::make('is_active')->label('فعال')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('نام'),
                TextColumn::make('key_prefix')->label('کلید')->formatStateUsing(fn (string $state) => $state.'…')
                    ->fontFamily('mono')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('allowed_providers')->label('ارائه‌دهنده‌ها')->badge()->placeholder('همه'),
                TextColumn::make('allowed_models')->label('مدل‌ها')->badge()->placeholder('همه')->limitList(3),
                Fields::usdColumn('spent')->label('هزینه‌شده'),
                TextColumn::make('spend_limit')->label('مصرف / سقف دوره')->placeholder('نامحدود')
                    ->formatStateUsing(fn (AppApiKey $record) => Money::format($record->spentThisPeriod()).' / '.Money::format($record->spend_limit).' '.$record->spend_limit_period->label())
                    ->color(fn (AppApiKey $record) => $record->isOverSpendLimit() ? 'danger' : null)
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('rate_limit_per_minute')->label('درخواست در دقیقه')
                    ->state(fn (AppApiKey $record) => $record->rateLimitPerMinute() ?: 'نامحدود')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_used_at')->label('آخرین استفاده')->jalaliDateTime()->placeholder('—'),
                TextColumn::make('expires_at')->label('انقضا')->jalaliDateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')->label('فعال'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data): AppApiKey {
                        [$key, $plain] = app(ApiKeyService::class)->create($this->getOwnerRecord(), $this->normalize($data));

                        Notification::make()->success()->persistent()
                            ->title('کلید ساخته شد — همین حالا کپی کنید')
                            ->body($plain)
                            ->send();

                        return $key;
                    }),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => $this->normalize($data)),
                DeleteAction::make()->label('ابطال'),
            ]);
    }

    private function normalize(array $data): array
    {
        foreach (['allowed_providers', 'allowed_models'] as $field) {
            if (array_key_exists($field, $data) && empty($data[$field])) {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
