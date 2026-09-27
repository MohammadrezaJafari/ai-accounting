<?php

namespace App\Filament\Resources\Agents;

use App\Filament\Resources\Agents\Pages\CreateAgent;
use App\Filament\Resources\Agents\Pages\EditAgent;
use App\Filament\Resources\Agents\Pages\ListAgents;
use App\Filament\Resources\Agents\RelationManagers\PackagesRelationManager;
use App\Filament\Resources\Agents\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Agents\Schemas\AgentForm;
use App\Filament\Resources\Agents\Tables\AgentsTable;
use App\Models\Agent;
use App\Support\AgentStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Marketplace listings sold per unit: their packages, runs and cost per unit, and the review
 * of listings made by publishers.
 */
class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'ایجنت‌ها';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'ایجنت';

    protected static ?string $pluralModelLabel = 'ایجنت‌ها';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Listings waiting for review: new submissions and changes to live listings.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Agent::query()->where(fn ($query) => $query->where('status', AgentStatus::PendingReview)->orWhereNotNull('pending_changes'))->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'در انتظار بررسی';
    }

    public static function form(Schema $schema): Schema
    {
        return AgentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PackagesRelationManager::class,
            RunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgents::route('/'),
            'create' => CreateAgent::route('/create'),
            'edit' => EditAgent::route('/{record}/edit'),
        ];
    }
}
