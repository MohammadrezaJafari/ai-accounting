<?php

namespace App\Filament\Resources\Organizations\Tables;

use App\Filament\Support\Fields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['members', 'apps'])->withSum('apps', 'balance'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('سازمان')->searchable()->sortable(),
                TextColumn::make('members_count')->label('اعضا'),
                TextColumn::make('apps_count')->label('اپ‌ها'),
                Fields::usdColumn('apps_sum_balance')->label('موجودی کل'),
                TextColumn::make('created_at')->label('ایجاد')->jalaliDateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
