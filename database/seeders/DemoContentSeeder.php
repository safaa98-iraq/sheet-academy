<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CurriculumNode;
use App\Models\GradeLevel;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentTokenService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo content is only available in local and testing environments.');
        }

        $issuedTokens = DB::transaction(function (): array {
            $this->call(GradeLevelSeeder::class);
            $admin = User::where('is_super_admin', true)->first();
            $definitions = [
                ['head-neck', 'تشريح الرأس والعنق', 'الصف الأول', 'المعالم التشريحية للرأس والعنق'],
                ['dental-anatomy', 'تشريح الأسنان', 'الصف الأول', 'التشريح الوصفي للأسنان الدائمة'],
                ['pharmacology', 'علم الأدوية لطب الأسنان', 'الصف الثاني', 'المصطلحات الأساسية في علم الأدوية'],
                ['radiology', 'الأشعة والتشخيص', 'الصف الثاني', 'قراءة الصور وتوثيق الملاحظات'],
                ['orthodontics', 'أساسيات تقويم الأسنان', 'الصف الثالث', 'مبادئ حركة الأسنان'],
                ['endodontics', 'علاج الجذور السريري', 'الصف الرابع', 'مقدمة في علاج الجذور'],
            ];
            foreach ($definitions as $position => [$slug, $title, $grade, $topic]) {
                $course = Course::withTrashed()->firstOrCreate(['slug' => 'demo-'.$slug], [
                    'title' => $title, 'description' => 'مادة تجريبية لاستكشاف المنصة: '.$topic.'. دروس منظمة، ومراجعات قصيرة، وأنشطة لتدوين الملاحظات.',
                    'grade_level_id' => GradeLevel::where('name', $grade)->value('id'),
                    'created_by' => $admin?->id, 'position' => $position + 1,
                    'publication_status' => 'published', 'published_at' => now(),
                ]);
                if (! $course->wasRecentlyCreated) {
                    continue;
                }
                $root = $this->node($course, null, 'material', $title, 0);
                $titles = ['مقدمة في المادة وأهداف التعلّم', 'المفاهيم الأساسية والمصطلحات', $topic, 'العلاقات والمقارنات الأساسية', 'تطبيقات وأسئلة للمناقشة', 'المراجعة النهائية والتقييم الذاتي'];
                for ($partIndex = 0; $partIndex < 2; $partIndex++) {
                    $part = $this->node($course, $root, 'part', $partIndex === 0 ? 'الجزء الأول · الأساسيات' : 'الجزء الثاني · التطبيق والمراجعة', $partIndex);
                    $chapter = $this->node($course, $part, 'chapter', $partIndex === 0 ? 'الفصل الأول · بناء المعرفة' : 'الفصل الثاني · تثبيت المعرفة', 0);
                    $section = $this->node($course, $chapter, 'section', $partIndex === 0 ? 'المفاهيم والشرح' : 'الأنشطة والمراجعة', 0);
                    for ($offset = 0; $offset < 3; $offset++) {
                        $lessonIndex = $partIndex * 3 + $offset;
                        $lessonTitle = $titles[$lessonIndex];
                        $lesson = $course->lessons()->create([
                            'title' => $lessonTitle, 'slug' => 'demo-'.$slug.'-'.($lessonIndex + 1),
                            'type' => 'text', 'position' => $lessonIndex, 'duration_seconds' => [540, 750, 1080, 960, 840, 360][$lessonIndex],
                            'is_published' => true, 'publication_status' => 'published',
                            'description' => 'درس تجريبي في '.$title.' يساعدك على استكشاف القراءة وتدوين الملاحظات ومتابعة التقدم.',
                            'body_html' => '<h2>'.e($lessonTitle).'</h2><p>هذا محتوى تجريبي لمادة '.e($title).'، معد لاختبار تجربة التعلّم داخل المنصة.</p><h3>أهداف الدرس</h3><ul><li>التعرّف على موضوع '.e($topic).'.</li><li>تنظيم المصطلحات الجديدة في ملاحظات واضحة.</li><li>ربط هذا الدرس بما سبقه في المادة.</li></ul><h3>نشاط التعلّم</h3><p>دوّن ثلاث نقاط ترغب في فهمها، ثم أعد صياغة الفكرة الرئيسية بأسلوبك. أضف هذا الدرس إلى قائمة المراجعة للعودة إليه لاحقاً.</p><h3>راجع فهمك</h3><ol><li>ما الفكرة الرئيسية للدرس؟</li><li>ما المصطلح الذي يحتاج إلى مراجعة؟</li><li>ما السؤال الذي ستطرحه على أستاذ المادة؟</li></ol><p>بعد إنهاء القراءة، استخدم زر تحديد كمكتمل لمتابعة تقدّمك.</p>',
                        ]);
                        CurriculumNode::create(['course_id' => $course->id, 'parent_id' => $section->id, 'lesson_id' => $lesson->id, 'type' => 'lesson', 'title' => $lesson->title, 'position' => $offset, 'publication_status' => 'published']);
                    }
                }
            }

            $demoCourses = Course::whereIn('slug', array_map(fn (array $definition): string => 'demo-'.$definition[0], $definitions))->orderBy('position')->get();
            $names = ['علي حسين', 'فاطمة محمد', 'مصطفى أحمد', 'زينب حسن', 'حيدر كريم', 'مريم عبد الله'];
            $issued = [];
            foreach ($names as $index => $name) {
                $student = Student::firstOrCreate(['email' => 'demo.student.'.($index + 1).'@example.test'], ['name' => $name, 'status' => 'active', 'created_by' => $admin?->id]);
                if (! $student->wasRecentlyCreated) {
                    continue;
                }
                $enrolled = $index === 0 ? $demoCourses : $demoCourses->filter(fn (Course $course, int $key): bool => ($key + $index) % 2 === 0);
                $student->courses()->syncWithoutDetaching($enrolled->modelKeys());
                $token = app(StudentTokenService::class)->issue($student, $admin);
                $issued[] = [$name, $token['token']];
                $playlist = $student->playlists()->create(['name' => 'مراجعة هذا الأسبوع']);
                foreach ($enrolled as $course) {
                    foreach ($course->lessons as $lessonIndex => $lesson) {
                        if ($lessonIndex < ($index === 3 ? 6 : ($index % 4) + 1)) {
                            LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'last_position_seconds' => $lesson->duration_seconds, 'watched_seconds' => $lesson->duration_seconds, 'completed_at' => now()->subDays(2)->addMinutes($lessonIndex)]);
                        }
                        if ($lessonIndex === 2) {
                            $playlist->items()->create(['lesson_id' => $lesson->id, 'position' => $playlist->items()->count()]);
                        }
                    }
                }
            }

            return $issued;
        });

        if ($issuedTokens !== [] && $this->command !== null) {
            $this->command->info('New demo student tokens (shown once; existing students and tokens are preserved):');
            $this->command->table(['Student', 'Token'], $issuedTokens);
        }
    }

    private function node(Course $course, ?CurriculumNode $parent, string $type, string $title, int $position): CurriculumNode
    {
        return CurriculumNode::create(['course_id' => $course->id, 'parent_id' => $parent?->id, 'type' => $type, 'title' => $title, 'position' => $position, 'publication_status' => 'published']);
    }
}
