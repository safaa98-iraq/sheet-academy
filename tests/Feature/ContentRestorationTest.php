<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\GradeLevel;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentRestorationTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_requires_management_permission_confirmation_and_an_archived_target(): void
    {
        $grade = GradeLevel::factory()->create();
        $grade->delete();
        $url = route('admin.archive.restore', ['type' => 'grade', 'id' => $grade->id]);
        $this->post($url, ['confirmed' => true])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(), 'web')->post($url, ['confirmed' => true])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web')->post($url)->assertSessionHasErrors('confirmed');
        $this->assertSoftDeleted($grade);
        $this->post($url, ['confirmed' => true])->assertRedirect(route('admin.archive.index', ['type' => 'grade']));
        $this->post($url, ['confirmed' => true])->assertNotFound();
        $this->post('/admin/archive/invalid/1/restore', ['confirmed' => true])->assertNotFound();
    }

    public function test_restore_obeys_parent_order_preserves_progress_and_keeps_other_archived_lessons_hidden(): void
    {
        $grade = GradeLevel::factory()->create();
        $course = Course::factory()->for($grade)->create(['publication_status' => 'published']);
        $lesson = Lesson::factory()->for($course)->create(['publication_status' => 'published']);
        $other = Lesson::factory()->for($course)->create();
        $root = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => 'المادة', 'publication_status' => 'published', 'position' => 0]);
        $section = CurriculumNode::create(['course_id' => $course->id, 'parent_id' => $root->id, 'type' => 'section', 'title' => 'القسم', 'publication_status' => 'published', 'position' => 0]);
        $node = CurriculumNode::create(['course_id' => $course->id, 'parent_id' => $section->id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title, 'publication_status' => 'published', 'position' => 0]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => 42]);
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'disk' => 'private', 'path' => 'retained.pdf', 'original_name' => 'ملزمة.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 4]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $preview = $this->get(route('admin.grade-levels.delete', $grade))->assertOk();
        $this->delete(route('admin.grade-levels.destroy', $grade), ['confirmed' => true, 'confirmation' => $preview->viewData('nonce')])->assertRedirect();
        $restore = fn ($type, $item) => $this->post(route('admin.archive.restore', ['type' => $type, 'id' => $item->id]), ['confirmed' => true]);
        $restore('lesson', $lesson)->assertSessionHasErrors('restore');
        $restore('course', $course)->assertSessionHasErrors('restore');
        $restore('grade', $grade)->assertRedirect();
        $this->assertSoftDeleted($course);
        $restore('course', $course)->assertRedirect();
        $this->get(route('admin.courses.curriculum', $course))->assertOk();
        $this->assertSoftDeleted($lesson);
        $restore('lesson', $lesson)->assertRedirect();
        $this->assertFalse($node->fresh()->trashed());
        $this->assertFalse($section->fresh()->trashed());
        $this->assertSoftDeleted($other);
        $this->assertModelExists($attachment);
        $this->assertDatabaseHas('lesson_progress', ['lesson_id' => $lesson->id, 'last_position_seconds' => 42]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'content_restored_by_admin']);
        $this->loginStudent(app(StudentTokenService::class)->issue($student)['token']);
        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk()->assertSee($lesson->title);
        $this->get(route('student.lesson.show', $other))->assertNotFound();
    }

    public function test_archiving_a_curriculum_section_preserves_attachments_for_restoration(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create();
        $section = CurriculumNode::create(['course_id' => $course->id, 'type' => 'section', 'title' => 'القسم', 'publication_status' => 'published', 'position' => 0]);
        CurriculumNode::create(['course_id' => $course->id, 'parent_id' => $section->id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title, 'publication_status' => 'published', 'position' => 0]);
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'disk' => 'private', 'path' => 'retained.pdf', 'original_name' => 'ملزمة.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 4]);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'web');
        $this->delete(route('admin.curriculum-nodes.destroy', $section))->assertRedirect();
        $this->assertSoftDeleted($lesson);
        $this->assertModelExists($attachment);
        $this->post(route('admin.archive.restore', ['type' => 'lesson', 'id' => $lesson->id]), ['confirmed' => true])->assertRedirect();
        $this->assertFalse($lesson->fresh()->trashed());
        $this->assertModelExists($attachment);
    }
}
