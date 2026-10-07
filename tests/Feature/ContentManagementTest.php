<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_build_a_safe_ordered_curriculum_and_student_sees_only_assigned_published_material(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create(['title' => 'تشريح الأسنان', 'publication_status' => 'published']);
        $root = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'publication_status' => 'published']);
        $this->actingAs($teacher, 'web');

        $this->get(route('admin.courses.curriculum', $course))->assertOk()->assertSee('منهج المادة');
        $this->from(route('admin.courses.curriculum', $course))->post(route('admin.courses.curriculum.store', $course), [
            'parent_id' => $root->id, 'type' => 'part', 'title' => 'مقدمة', 'publication_status' => 'published',
        ])->assertRedirect();
        $part = CurriculumNode::where('course_id', $course->id)->where('type', 'part')->firstOrFail();
        $this->post(route('admin.courses.curriculum.store', $course), [
            'parent_id' => $part->id, 'type' => 'chapter', 'title' => 'الفصل الأول', 'publication_status' => 'published',
        ]);
        $chapter = CurriculumNode::where('course_id', $course->id)->where('type', 'chapter')->firstOrFail();
        $this->post(route('admin.courses.curriculum.store', $course), [
            'parent_id' => $chapter->id, 'type' => 'section', 'title' => 'القسم الأول', 'publication_status' => 'published',
        ]);
        $section = CurriculumNode::where('course_id', $course->id)->where('type', 'section')->firstOrFail();
        $this->post(route('admin.courses.curriculum.store', $course), [
            'parent_id' => $section->id,
            'type' => 'lesson',
            'title' => 'بنية السن',
            'content_type' => 'text',
            'duration_seconds' => 180,
            'publication_status' => 'published',
            'body_html' => '<p onclick="alert(1)">نص آمن</p><script>alert(2)</script><a href="javascript:alert(3)">رابط</a>',
        ]);

        $lesson = Lesson::where('course_id', $course->id)->firstOrFail();
        $this->assertStringNotContainsString('<script', $lesson->body_html);
        $this->assertStringNotContainsString('onclick', $lesson->body_html);
        $this->assertStringNotContainsString('javascript:', $lesson->body_html);
        $this->get(route('admin.courses.preview', $course))->assertOk()->assertSee('بنية السن');
        $this->get(route('admin.courses.preview', ['course' => $course, 'lesson' => $lesson->id]))->assertOk()->assertSee('نص آمن');

        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $this->get(route('student.course.show', $course))->assertOk()->assertSee('مقدمة')->assertSee('الفصل الأول')->assertSee('القسم الأول')->assertSee('بنية السن');
        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk()->assertSee('نص آمن')->assertDontSee('alert(2)')->assertDontSee('onclick');

        $unassigned = Student::factory()->create();
        $otherToken = app(StudentTokenService::class)->issue($unassigned);
        $this->post('/logout');
        $this->loginStudent($otherToken['token']);
        $this->get(route('student.course.show', $course))->assertNotFound();
        $this->get(route('student.lesson.show', $lesson))->assertNotFound();
    }

    public function test_private_lesson_attachments_are_mime_checked_and_require_course_enrolment(): void
    {
        Storage::fake('private');
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create(['publication_status' => 'published']);
        [$lesson] = $this->createPublishedLesson($course);
        $this->actingAs($teacher, 'web');

        $this->post(route('admin.lessons.attachments.store', $lesson), [
            'attachments' => [UploadedFile::fake()->image('radiograph.png')],
        ])->assertRedirect();
        $attachment = LessonAttachment::where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame('private', $attachment->disk);
        $this->assertSame('image/png', $attachment->mime_type);
        Storage::disk('private')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);

        $spoofedPath = tempnam(sys_get_temp_dir(), 'academy-upload-');
        file_put_contents($spoofedPath, '<?php echo "not a PDF";');
        $spoofedPdf = new UploadedFile($spoofedPath, 'spoofed.pdf', null, UPLOAD_ERR_OK, true);
        $this->from(route('admin.courses.edit', $course))->post(route('admin.lessons.attachments.store', $lesson), [
            'attachments' => [$spoofedPdf],
        ])->assertSessionHasErrors('attachments.0');
        unlink($spoofedPath);

        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $viewerUrl = $this->issueStudentViewLink('document', $attachment->id);
        $viewerResponse = $this->get($viewerUrl)->assertOk()->assertSee('data-document-image', false);
        preg_match('/src="([^"]+\/documents\/[^" ]+\/pages\/1\?[^" ]+)"/', $viewerResponse->getContent(), $imageUrl);
        $this->assertNotEmpty($imageUrl[1] ?? null);
        $this->get(html_entity_decode($imageUrl[1]))->assertOk()->assertHeader('Content-Type', 'image/png');

        $stranger = Student::factory()->create();
        $strangerToken = app(StudentTokenService::class)->issue($stranger);
        $this->post('/logout');
        $this->loginStudent($strangerToken['token']);
        $this->get(route('student.lesson-attachments.download', $attachment))->assertNotFound();
    }

    public function test_administrator_without_course_permission_cannot_edit_curriculum(): void
    {
        $admin = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($admin, 'web')->get(route('admin.courses.curriculum', $course))->assertForbidden();
        $this->post(route('admin.courses.curriculum.store', $course), [
            'parent_id' => 1, 'type' => 'part', 'title' => 'غير مصرح', 'publication_status' => 'draft',
        ])->assertForbidden();
    }

    public function test_scheduled_material_becomes_visible_only_after_its_publication_time(): void
    {
        $course = Course::factory()->create([
            'published_at' => null,
            'publication_status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
        ]);
        $student = Student::factory()->create();
        $student->courses()->attach($course);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);
        $this->get(route('student.course.show', $course))->assertNotFound();

        $course->update(['scheduled_at' => now()->subMinute()]);
        $this->get(route('student.course.show', $course))->assertOk();
    }

    public function test_curriculum_can_be_reordered_and_soft_deleted(): void
    {
        $teacher = User::factory()->create(['is_super_admin' => true]);
        $course = Course::factory()->create();
        [$lesson, $root, $part, , $section, $node] = $this->createPublishedLesson($course);
        $secondPart = $root->children()->create(['course_id' => $course->id, 'type' => 'part', 'title' => 'جزء ثانٍ', 'position' => 1, 'publication_status' => 'published']);

        $this->actingAs($teacher, 'web')->patchJson(route('admin.courses.curriculum.order', $course), [
            'parent_id' => $root->id,
            'node_ids' => [$secondPart->id, $part->id],
        ])->assertOk();
        $this->assertSame(0, $secondPart->fresh()->position);
        $this->assertSame(1, $part->fresh()->position);

        $secondChapter = $part->children()->create(['course_id' => $course->id, 'type' => 'chapter', 'title' => 'فصل آخر', 'position' => 1, 'publication_status' => 'published']);
        $this->patchJson(route('admin.courses.curriculum.order', $course), [
            'parent_id' => $secondChapter->id,
            'node_ids' => [$section->id],
        ])->assertOk();
        $this->assertSame($secondChapter->id, $section->fresh()->parent_id);

        $this->delete(route('admin.curriculum-nodes.destroy', $section))->assertRedirect();
        $this->assertSoftDeleted('curriculum_nodes', ['id' => $section->id]);
        $this->assertSoftDeleted('curriculum_nodes', ['id' => $node->id]);
        $this->assertSoftDeleted('lessons', ['id' => $lesson->id]);
    }

    /** @return array{Lesson, CurriculumNode, CurriculumNode, CurriculumNode, CurriculumNode, CurriculumNode} */
    private function createPublishedLesson(Course $course): array
    {
        $root = CurriculumNode::create(['course_id' => $course->id, 'type' => 'material', 'title' => $course->title, 'publication_status' => 'published']);
        $part = $root->children()->create(['course_id' => $course->id, 'type' => 'part', 'title' => 'الجزء الأول', 'publication_status' => 'published']);
        $chapter = $part->children()->create(['course_id' => $course->id, 'type' => 'chapter', 'title' => 'الفصل الأول', 'publication_status' => 'published']);
        $section = $chapter->children()->create(['course_id' => $course->id, 'type' => 'section', 'title' => 'القسم الأول', 'publication_status' => 'published']);
        $lesson = $course->lessons()->create([
            'title' => 'درس آمن', 'slug' => 'safe-lesson', 'type' => 'text', 'duration_seconds' => 300,
            'is_published' => true, 'publication_status' => 'published',
        ]);
        $node = $section->children()->create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'type' => 'lesson',
            'title' => $lesson->title, 'publication_status' => 'published',
        ]);

        return [$lesson, $root, $part, $chapter, $section, $node];
    }
}
