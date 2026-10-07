<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'course_id' => Course::factory(),
            'title' => $title,
            'slug' => fake()->unique()->slug(),
            'type' => 'video',
            'duration_seconds' => 600,
            'position' => 1,
            'is_published' => true,
            'publication_status' => 'draft',
        ];
    }
}
