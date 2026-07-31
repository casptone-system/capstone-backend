<?php

namespace Database\Factories;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Review>
 */
class ReviewFactory extends Factory
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
            'cycle_id' => AccreditationCycle::factory(),
            'current_status' => 'Draft',
            'submitted_by' => User::factory(),
            'submitted_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the review is submitted.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_status' => 'Submitted',
            'submitted_at' => now(),
        ]);
    }

    /**
     * Indicate that the review is completed (Ready).
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_status' => 'Ready',
            'submitted_at' => now()->subDays(5),
            'completed_at' => now(),
        ]);
    }
}