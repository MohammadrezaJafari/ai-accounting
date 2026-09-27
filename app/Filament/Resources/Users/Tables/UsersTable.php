<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Support\Fields;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('apps')->withSum('apps', 'balance'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('نام')->searchable()->sortable(),
                TextColumn::make('email')->label('ایمیل')->searchable(),
                TextColumn::make('role')->label('نقش')->badge()
                    ->formatStateUsing(fn (string $state) => $state === User::ROLE_ADMIN ? 'مدیر' : 'مشتری')
                    ->color(fn (string $state) => $state === User::ROLE_ADMIN ? 'warning' : 'gray'),
                TextColumn::make('apps_count')->label('اپ‌ها'),
                Fields::usdColumn('apps_sum_balance')->label('موجودی کل'),
                ToggleColumn::make('is_active')->label('فعال'),
                TextColumn::make('created_at')->label('عضویت')->jalaliDateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('نقش')->options([User::ROLE_CUSTOMER => 'مشتری', User::ROLE_ADMIN => 'مدیر']),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
