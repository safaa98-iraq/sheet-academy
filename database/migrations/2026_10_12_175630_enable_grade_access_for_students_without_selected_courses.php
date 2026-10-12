<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $count = DB::table('students')->whereNull('grade_level_id')->whereNotNull('study_grade_level_id')
            ->whereExists(fn ($grade) => $grade->selectRaw('1')->from('grade_levels')->whereColumn('grade_levels.id', 'students.study_grade_level_id')->whereNull('grade_levels.deleted_at'))
            ->whereNotExists(fn ($enrollment) => $enrollment->selectRaw('1')->from('course_student')->whereColumn('course_student.student_id', 'students.id'))
            ->update(['grade_level_id' => DB::raw('study_grade_level_id')]);
        Log::info('student_grade_access.initialized', ['students_count' => $count]);
    }

    public function down(): void
    {
        // Preserve assignments: migrated rows cannot safely be distinguished from later teacher choices.
    }
};
