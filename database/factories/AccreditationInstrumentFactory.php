<?php

namespace Database\Factories;

use App\Models\AccreditationInstrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AccreditationInstrument>
 */
class AccreditationInstrumentFactory extends Factory
{
    protected $model = AccreditationInstrument::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'version' => $this->faker->numerify('##.#'),
            'description' => $this->faker->paragraph(),
            'is_active' => true,
        ];
    }
}
