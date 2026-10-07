<?php

namespace Database\Factories;

use App\Models\GradeLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GradeLevel> */
class GradeLevelFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->sentence(2), 'position' => 0];
    }
}
