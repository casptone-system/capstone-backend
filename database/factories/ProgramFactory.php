<?php

namespace Database\Factories;

use App\Models\College;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Program>
 */
class ProgramFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'college_id' => College::factory(),
            'name' => 'Bachelor of ' . ucfirst(fake()->word()),
            'code' => strtoupper(fake()->unique()->word()),
            'chair' => 'Dr. ' . fake()->name(),
            'accreditation_status' => fake()->randomElement(['compliant', 'at-risk', 'non-compliant']),
            'compliance_score' => fake()->numberBetween(0, 100),
        ];
    }
}
