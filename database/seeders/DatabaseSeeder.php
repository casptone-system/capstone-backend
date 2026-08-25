<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            OrgStructureSeeder::class,
            AccreditationAreaSeeder::class,
            InstrumentTemplateSeeder::class,
        ]);

        if (!app()->environment('production')) {
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test User',
                    'first_name' => 'Test',
                    'middle_name' => null,
                    'last_name' => 'User',
                    'email' => 'test@example.com',
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
