<?php

namespace Database\Factories;

use App\Models\AccreditationArea;
use App\Models\AreaMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AreaMember>
 */
class AreaMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'area_id' => AccreditationArea::factory(),
            'user_id' => User::factory(),
            'role' => fake()->randomElement(['Member', 'Document Controller', 'Coordinator']),
        ];
    }
}