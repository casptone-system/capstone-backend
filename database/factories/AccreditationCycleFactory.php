<?php

namespace Database\Factories;

use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AccreditationCycle>
 */
class AccreditationCycleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $program = Program::query()->first() ?? Program::factory()->create();

        return [
            'program_id' => $program->id,
            'college_id' => $program->college_id,
            'level' => fake()->randomElement(AccreditationCycle::LEVELS),
            'status' => fake()->randomElement(AccreditationCycle::STATUSES),
            'phase' => fake()->randomElement(['Planning', 'Formal Survey', 'Follow-Up', 'Monitoring']),
            'instrument_name' => fake()->randomElement(['Instrument A', 'Instrument B', 'Quality Audit Form']),
            'valid_until' => fake()->dateTimeBetween('now', '+2 years')->format('Y-m-d'),
            'scheduled_visit' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'remarks' => fake()->sentence(),
        ];
    }
}
