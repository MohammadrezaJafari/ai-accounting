<?php

namespace App\Providers;

use App\Models\User;
use App\Support\OrganizationPermission;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePanel();
        $this->defineOrganizationGates();
    }

    /**
     * One gate per organization permission, checked against the user's role in their current organization.
     */
    private function defineOrganizationGates(): void
    {
        foreach (OrganizationPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->isAdmin() || $user->canInCurrentOrganization($permission));
        }
    }

    /**
     * پیش‌فرض‌های پنل مدیریت، هم‌سان با پنل بیمه.
     */
    private function configurePanel(): void
    {
        // Data stays in UTC; everything the operator sees is in Tehran time.
        $timezone = config('billing.display_timezone');
        FilamentTimezone::set($timezone);

        TextColumn::configureUsing(fn (TextColumn $column) => $column->timezone($timezone));
        TextEntry::configureUsing(fn (TextEntry $entry) => $entry->timezone($timezone));

        // Only real date-time pickers convert timezones; date-only values must not shift a day.
        DateTimePicker::configureUsing(function (DateTimePicker $picker) use ($timezone) {
            if (! $picker instanceof DatePicker) {
                $picker->timezone($timezone);
            }
        });

        Table::configureUsing(fn (Table $table) => $table
            ->defaultPaginationPageOption(25)
            ->striped());
    }
}
