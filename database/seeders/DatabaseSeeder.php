<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => env('ADMIN_EMAIL', 'admin@example.com')], [
            'name' => 'Administrator',
            'password' => env('ADMIN_PASSWORD', 'password'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->call(CatalogSeeder::class);
    }
}
