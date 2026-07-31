<?php

namespace Database\Factories;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AccreditationArea>
 */
class AccreditationAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cycle_id' => AccreditationCycle::factory(),
            'name' => fake()->randomElement([
                'Area I: Vision, Mission, Goals',
                'Area II: Faculty',
                'Area III: Curriculum',
                'Area IV: Support to Students',
                'Area V: Research',
                'Area VI: Extension',
                'Area VII: Library',
                'Area VIII: Physical Plant',
                'Area IX: Laboratories',
                'Area X: Administration',
            ]),
            'description' => fake()->sentence(),
            'chair_id' => null,
            'status' => fake()->randomElement(AccreditationArea::STATUSES),
        ];
    }

    /**
     * Indicate that the area has a chair assigned.
     */
    public function withChair(): static
    {
        return $this->state(fn (array $attributes) => [
            'chair_id' => User::factory(),
        ]);
    }
}