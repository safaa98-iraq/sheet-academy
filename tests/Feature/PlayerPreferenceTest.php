<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_player_preferences_are_saved_to_the_database(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);

        $this->putJson('/player-preferences', [
            'video_quality' => '720p',
            'playback_speed' => 1.5,
            'is_muted' => true,
        ])->assertOk()->assertJson(['video_quality' => '720p', 'playback_speed' => 1.5, 'is_muted' => true]);

        $this->assertDatabaseHas('student_preferences', [
            'student_id' => $student->id,
            'video_quality' => '720p',
            'playback_speed' => 1.5,
            'is_muted' => true,
        ]);
    }
}
