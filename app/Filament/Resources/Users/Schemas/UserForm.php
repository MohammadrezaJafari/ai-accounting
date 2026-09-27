<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('نام')->required()->maxLength(255),
                TextInput::make('email')->label('ایمیل')->email()->required()->unique(ignoreRecord: true)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('password')->label('رمز عبور')->password()->revealable()->minLength(8)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'برای حفظ رمز فعلی خالی بگذارید.' : null),
                Select::make('role')->label('نقش')->required()->default(User::ROLE_CUSTOMER)->options([
                    User::ROLE_CUSTOMER => 'مشتری',
                    User::ROLE_ADMIN => 'مدیر',
                ]),
                Toggle::make('is_active')->label('فعال')->default(true),
            ]);
    }
}
