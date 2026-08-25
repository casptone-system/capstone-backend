<?php

namespace Database\Factories;

use App\Models\College;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\College>
 */
class CollegeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' College',
            'code' => strtoupper(fake()->unique()->word()),
            'campus' => config('institution.campus', 'Echague Main Campus'),
            'description' => fake()->sentence(),
        ];
    }
}
