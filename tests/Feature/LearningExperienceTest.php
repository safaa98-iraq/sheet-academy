<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Student;
use App\Services\StudentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearningExperienceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function previewPages(): array
    {
        return [
            'learning' => ['learning', 'مكتبة موادك'],
            'login' => ['login', 'رمز الوصول'],
            'course' => ['course', 'محتوى المادة'],
            'lesson' => ['lesson', 'تحديد كمكتمل'],
            'viewer' => ['viewer', 'عارض المرفقات'],
            'playlist' => ['playlist', 'قائمة التشغيل'],
            'progress' => ['progress', 'التقدّم حسب المادة'],
            'admin' => ['admin', 'نظرة عامة'],
            'curriculum' => ['curriculum', 'المواد والمحتوى'],
            'editor' => ['editor', 'النشر والتنظيم'],
            'students' => ['students', 'إدارة الطلاب'],
            'student-progress' => ['student-progress', 'تقدم الطلاب'],
            'student-detail' => ['student-detail', 'رحلة الطالب'],
            'admins' => ['admins', 'مصفوفة الصلاحيات'],
            'activity' => ['activity', 'سجل النشاط'],
            'settings' => ['settings', 'تجربة التعلّم'],
        ];
    }

    #[DataProvider('previewPages')]
    public function test_removed_preview_pages_are_not_found(string $page, string $heading): void
    {
        $this->get('/preview/'.$page)->assertNotFound();
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_unknown_preview_page_and_course_are_not_found(): void
    {
        $this->get('/preview/private')->assertNotFound();
        $this->get('/preview/course?course=missing')->assertNotFound();
        $this->get('/preview/lesson?lesson=missing')->assertNotFound();
    }

    public function test_removed_preview_editor_does_not_accept_local_curriculum_ids(): void
    {
        $this->get('/preview/editor?course=anatomy&lesson=local-lesson-123')
            ->assertNotFound();
        $this->get('/preview/curriculum?course=anatomy')
            ->assertNotFound();
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_student_can_view_only_published_enrolled_content(): void
    {
        $student = Student::factory()->create();
        $course = Course::factory()->create(['title' => 'مادة الطالب']);
        $student->courses()->attach($course);
        $lesson = Lesson::factory()->for($course)->create(['title' => 'الدرس المنشور', 'slug' => 'published', 'type' => 'video', 'duration_seconds' => 750, 'position' => 1, 'is_published' => true]);
        $draft = Lesson::factory()->for($course)->create(['title' => 'الدرس المخفي', 'slug' => 'draft', 'type' => 'video', 'duration_seconds' => 600, 'position' => 2, 'is_published' => false]);
        $otherCourse = Course::factory()->create();
        $otherLesson = Lesson::factory()->for($otherCourse)->create(['title' => 'درس طالب آخر', 'slug' => 'other', 'type' => 'video', 'duration_seconds' => 600, 'position' => 1, 'is_published' => true]);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);

        $this->get('/learning')->assertOk()->assertSee('مادة الطالب')->assertDontSee($otherCourse->title);
        $this->get(route('student.course.show', $course))->assertOk()->assertSee('الدرس المنشور')->assertDontSee('الدرس المخفي');
        $this->get($this->issueStudentViewLink('lesson', $lesson->id))->assertOk()->assertSee('الدرس المنشور')->assertSee('data-lesson-id="'.$lesson->id.'"', false);
        $this->get(route('student.lesson.show', $draft))->assertNotFound();
        $this->get(route('student.lesson.show', $otherLesson))->assertNotFound();
        $this->get(route('student.course.show', $otherCourse))->assertNotFound();
    }

    public function test_student_with_no_enrolments_sees_empty_states_and_can_open_auxiliary_pages(): void
    {
        $student = Student::factory()->create();
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);

        $this->get('/learning')->assertOk()->assertSee('مكتبتك بانتظار أول مادة');
        $this->get('/course')->assertOk()->assertSee('لا توجد مادة متاحة');
        $this->get('/viewer')->assertRedirect(route('student.learning'));
        $this->get('/playlist')->assertOk();
        $this->get('/progress')->assertOk()->assertSee('رحلتك تبدأ قريباً');
    }

    public function test_course_title_and_description_are_escaped(): void
    {
        $student = Student::factory()->create();
        $course = Course::factory()->create(['title' => '<script>alert("title")</script>', 'description' => '<img src=x onerror=alert(1)>']);
        $student->courses()->attach($course);
        $issued = app(StudentTokenService::class)->issue($student);
        $this->loginStudent($issued['token']);

        $this->get(route('student.course.show', $course))
            ->assertSee($course->title)->assertSee($course->description)
            ->assertDontSee($course->title, false)->assertDontSee($course->description, false);
    }

    public function test_offline_fallback_is_public_and_does_not_contain_student_content(): void
    {
        $this->get('/offline')->assertOk()->assertHeader('content-type', 'text/html; charset=utf-8');
    }
}
