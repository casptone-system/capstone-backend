<?php

namespace Database\Seeders;

use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
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
            AccreditationAreaSeeder::class,
        ]);

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

        College::factory(5)->create()->each(function ($college) {
            Program::factory(fake()->numberBetween(2, 5))->create([
                'college_id' => $college->id,
            ])->each(function ($program) {
                AccreditationCycle::factory(fake()->numberBetween(1, 3))->create([
                    'program_id' => $program->id,
                ]);
            });
        });
    }
}
