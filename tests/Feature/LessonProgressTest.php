<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LessonProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeats_upsert_actual_watched_time_and_resume_from_database(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $student = Student::factory()->create();
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->for($course)->create(['duration_seconds' => 100, 'publication_status' => 'published']);
        $student->courses()->attach($course);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);

        $this->postJson(route('student.progress.store', $lesson), ['event' => 'started', 'position_seconds' => 0, 'is_playing' => true])->assertOk();
        Carbon::setTestNow(now()->addSeconds(10));
        $this->postJson(route('student.progress.store', $lesson), ['event' => 'heartbeat', 'position_seconds' => 10, 'is_playing' => true])->assertOk()->assertJsonPath('watched_seconds', 10);
        Carbon::setTestNow(now()->addSeconds(10));
        $this->postJson(route('student.progress.store', $lesson), ['event' => 'heartbeat', 'position_seconds' => 90, 'is_playing' => true])->assertOk()->assertJsonPath('watched_seconds', 10)->assertJsonPath('position_seconds', 90);
        $this->assertDatabaseCount('lesson_progress', 1);
        $this->assertDatabaseHas('lesson_progress', ['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 90, 'watched_seconds' => 10, 'completed_at' => null]);
        Carbon::setTestNow();
    }

    public function test_student_cannot_save_progress_for_unassigned_course(): void
    {
        $student = Student::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);

        $this->postJson(route('student.progress.store', $lesson), ['event' => 'started', 'position_seconds' => 0, 'is_playing' => true])->assertNotFound();
        $this->assertDatabaseCount('lesson_progress', 0);
    }

    public function test_super_admin_can_view_filtered_progress_export_and_student_detail(): void
    {
        $this->seed(PermissionsAndRolesSeeder::class);
        $student = Student::factory()->create();
        $course = Course::factory()->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->for($course)->create(['publication_status' => 'published']);
        $student->courses()->attach($course);
        LessonProgress::query()->create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 42, 'watched_seconds' => 30]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');

        $this->get(route('admin.student-progress', ['course_id' => $course->id]))->assertOk()->assertSee($student->name);
        $this->get(route('admin.student-progress.print', ['course_id' => $course->id]))->assertOk()->assertSee($student->name);
        $this->get(route('admin.student-progress.show', $student))->assertOk()->assertSee($lesson->title)->assertSee('00:42');
        $this->get(route('admin.student-progress.export', ['course_id' => $course->id]))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
