<?php

namespace App\Filament\Resources\Apps;

use App\Filament\Resources\Apps\Pages\CreateApp;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Apps\RelationManagers\ApiKeysRelationManager;
use App\Filament\Resources\Apps\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Filament\Resources\Apps\Tables\AppsTable;
use App\Models\App;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AppResource extends Resource
{
    protected static ?string $model = App::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|\UnitEnum|null $navigationGroup = 'مشتریان';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'اپ';

    protected static ?string $pluralModelLabel = 'اپ‌ها';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AppForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ApiKeysRelationManager::class,
            TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApps::route('/'),
            'create' => CreateApp::route('/create'),
            'edit' => EditApp::route('/{record}/edit'),
        ];
    }
}
