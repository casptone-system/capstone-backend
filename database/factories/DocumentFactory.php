<?php

namespace Database\Factories;

use App\Models\AccreditationArea;
use App\Models\Document;
use App\Models\Program;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'area_id' => null,
            'task_id' => null,
            'content_row_id' => null,
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'school_year' => fake()->randomElement(['2025-2026', '2026-2027', '2027-2028']),
            'uploaded_by' => User::factory(),
            'current_version' => 1,
            'status' => 'Active',
        ];
    }

    /**
     * Indicate that the document belongs to an area.
     */
    public function withArea(): static
    {
        return $this->state(fn (array $attributes) => [
            'area_id' => AccreditationArea::factory(),
        ]);
    }

    /**
     * Indicate that the document belongs to a task.
     */
    public function withTask(): static
    {
        return $this->state(fn (array $attributes) => [
            'task_id' => Task::factory(),
        ]);
    }

    /**
     * Indicate that the document is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Archived',
        ]);
    }
}