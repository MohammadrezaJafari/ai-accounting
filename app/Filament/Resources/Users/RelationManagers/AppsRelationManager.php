<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Apps\AppResource;
use App\Filament\Support\Fields;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AppsRelationManager extends RelationManager
{
    protected static string $relationship = 'apps';

    protected static ?string $title = 'اپ‌ها';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('نام'),
                Fields::usdColumn('balance')->label('موجودی'),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->recordActions([
                Action::make('open')->label('مدیریت')->url(fn ($record) => AppResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
