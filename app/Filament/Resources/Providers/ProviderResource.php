<?php

namespace App\Filament\Resources\Providers;

use App\Filament\Resources\Providers\Pages\CreateProvider;
use App\Filament\Resources\Providers\Pages\EditProvider;
use App\Filament\Resources\Providers\Pages\ListProviders;
use App\Filament\Resources\Providers\RelationManagers\KeysRelationManager;
use App\Filament\Resources\Providers\Schemas\ProviderForm;
use App\Filament\Resources\Providers\Tables\ProvidersTable;
use App\Models\Provider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|\UnitEnum|null $navigationGroup = 'مدل‌ها';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'ارائه‌دهنده';

    protected static ?string $pluralModelLabel = 'ارائه‌دهنده‌ها';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProvidersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            KeysRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviders::route('/'),
            'create' => CreateProvider::route('/create'),
            'edit' => EditProvider::route('/{record}/edit'),
        ];
    }
}
