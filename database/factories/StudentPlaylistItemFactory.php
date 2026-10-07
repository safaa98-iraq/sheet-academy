<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\StudentPlaylist;
use App\Models\StudentPlaylistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentPlaylistItem>
 */
class StudentPlaylistItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_playlist_id' => StudentPlaylist::factory(),
            'lesson_id' => Lesson::factory(),
            'position' => 0,
        ];
    }
}
