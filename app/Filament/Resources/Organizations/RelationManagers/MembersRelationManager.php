<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Support\OrganizationRole;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'اعضا';

    protected static ?string $modelLabel = 'عضو';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::roleSelect(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('name')->label('نام'),
                TextColumn::make('email')->label('ایمیل')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('pivot.role')->label('نقش')->badge()
                    ->formatStateUsing(fn (OrganizationRole $state) => $state->label())
                    ->color(fn (OrganizationRole $state) => $state === OrganizationRole::Owner ? 'primary' : 'gray'),
                TextColumn::make('created_at')->label('عضویت')->jalaliDateTime(),
            ])
            ->headerActions([
                AttachAction::make()->label('افزودن عضو')->preloadRecordSelect()->recordSelectSearchColumns(['name', 'email'])
                    ->schema(fn (AttachAction $action) => [$action->getRecordSelect(), self::roleSelect()]),
            ])
            ->recordActions([
                EditAction::make()->label('تغییر نقش'),
                DetachAction::make()->label('حذف از سازمان'),
            ]);
    }

    private static function roleSelect(): Select
    {
        return Select::make('role')->label('نقش')->required()
            ->options(collect(OrganizationRole::cases())->mapWithKeys(fn (OrganizationRole $role) => [$role->value => $role->label()]))
            ->default(OrganizationRole::Developer->value);
    }
}
