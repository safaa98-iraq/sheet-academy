<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentPlaylist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentPlaylist>
 */
class StudentPlaylistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'name' => 'قائمتي',
        ];
    }
}
