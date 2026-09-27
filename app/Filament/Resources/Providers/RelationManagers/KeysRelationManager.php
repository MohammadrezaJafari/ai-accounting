<?php

namespace App\Filament\Resources\Providers\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * کلیدهای واقعی ما نزد ارائه‌دهنده؛ رمزنگاری‌شده ذخیره می‌شوند و هیچ‌وقت کامل نمایش داده نمی‌شوند.
 */
class KeysRelationManager extends RelationManager
{
    protected static string $relationship = 'keys';

    protected static ?string $title = 'کلیدهای API ارائه‌دهنده';

    protected static ?string $modelLabel = 'کلید';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('نام')->required()->maxLength(100),
                TextInput::make('api_key')->label('کلید API')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->formatStateUsing(fn () => null)
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'برای حفظ کلید فعلی خالی بگذارید.' : null),
                TextInput::make('priority')->label('اولویت')->numeric()->default(0)
                    ->helperText('کلیدهای با اولویت بالاتر اول استفاده می‌شوند؛ بین هم‌اولویت‌ها به‌صورت تصادفی پخش می‌شود.'),
                Toggle::make('is_active')->label('فعال')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('priority', 'desc')
            ->columns([
                TextColumn::make('name')->label('نام'),
                TextColumn::make('masked_key')->label('کلید')->fontFamily('mono')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('priority')->label('اولویت')->sortable(),
                TextColumn::make('failure_count')->label('خطای احراز')->badge()->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('last_used_at')->label('آخرین استفاده')->jalaliDateTime()->placeholder('—'),
                ToggleColumn::make('is_active')->label('فعال'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
