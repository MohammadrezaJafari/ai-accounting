<?php

namespace App\Filament\Resources\Publishers;

use App\Filament\Resources\Publishers\Pages\ListPublishers;
use App\Filament\Resources\Publishers\Tables\PublishersTable;
use App\Models\Organization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Organizations that publish agents in the marketplace, with what they are owed.
 * Payouts are recorded here or on the organization's page.
 */
class PublisherResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $slug = 'publishers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'ایجنت‌ها';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'ناشر';

    protected static ?string $pluralModelLabel = 'ناشران';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('publishedAgents')->withCount('publishedAgents');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PublishersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPublishers::route('/'),
        ];
    }
}
