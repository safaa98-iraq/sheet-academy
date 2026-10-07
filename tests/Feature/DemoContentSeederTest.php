<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_content_has_complete_curricula_enrollments_and_progress(): void
    {
        $this->seed(DemoContentSeeder::class);

        $this->assertDatabaseCount('grade_levels', 6);
        $this->assertDatabaseCount('courses', 6);
        $this->assertDatabaseCount('lessons', 36);
        $this->assertDatabaseCount('curriculum_nodes', 78);
        $this->assertDatabaseCount('students', 6);
        $this->assertDatabaseCount('student_tokens', 6);
        $student = Student::where('email', 'demo.student.1@example.test')->firstOrFail();
        $this->assertSame(6, $student->courses()->count());
        $this->assertSame(6, $student->progress()->whereNotNull('completed_at')->count());
        $this->assertSame(6, $student->playlists()->firstOrFail()->items()->count());
        $course = Course::where('slug', 'demo-dental-anatomy')->firstOrFail();
        $this->assertSame('الصف الأول', $course->gradeLevel->name);
        $this->assertSame(6, $course->lessons()->visibleForStudents()->count());
    }

    public function test_reseeding_preserves_edits_existing_tokens_and_deleted_content(): void
    {
        $this->seed(DemoContentSeeder::class);
        $course = Course::where('slug', 'demo-dental-anatomy')->firstOrFail();
        $course->update(['title' => 'عنوان عدّله المستخدم']);
        $course->lessons()->firstOrFail()->delete();
        $student = Student::where('email', 'demo.student.1@example.test')->firstOrFail();
        $student->update(['status' => 'frozen']);
        $tokenHash = $student->tokens()->firstOrFail()->token_hash;

        $this->seed(DemoContentSeeder::class);

        $this->assertDatabaseCount('courses', 6);
        $this->assertDatabaseCount('lessons', 36);
        $this->assertDatabaseCount('students', 6);
        $this->assertDatabaseCount('student_tokens', 6);
        $this->assertSame('عنوان عدّله المستخدم', $course->fresh()->title);
        $this->assertSame(5, $course->lessons()->count());
        $this->assertSame('frozen', $student->fresh()->status);
        $this->assertSame($tokenHash, $student->tokens()->firstOrFail()->token_hash);
    }
}
