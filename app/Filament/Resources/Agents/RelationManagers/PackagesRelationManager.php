<?php

namespace App\Filament\Resources\Agents\RelationManagers;

use App\Filament\Support\Fields;
use App\Models\AgentPackage;
use App\Support\Money;
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

class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = 'بسته‌ها';

    protected static ?string $modelLabel = 'بسته';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('نام')->required()->maxLength(100),
                TextInput::make('units')->label('تعداد واحد')->required()->integer()->minValue(1),
                Fields::usd('price')->label('قیمت')->required()->gt(0),
                TextInput::make('sort_order')->label('ترتیب')->numeric()->default(0),
                Toggle::make('is_active')->label('فعال')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('نام'),
                TextColumn::make('units')->label('واحد'),
                TextColumn::make('price')->label('قیمت')->formatStateUsing(fn ($state) => Money::format((int) $state))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('unit_price')->label('قیمت هر واحد')
                    ->state(fn (AgentPackage $record) => Money::format(intdiv($record->price, max(1, $record->units))))->extraAttributes(['dir' => 'ltr']),
                ToggleColumn::make('is_active')->label('فعال'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
