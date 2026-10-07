<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\SaveLessonProgressRequest;
use App\Models\Lesson;
use App\Services\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class LessonProgressController extends Controller
{
    public function store(SaveLessonProgressRequest $request, Lesson $lesson, LessonProgressService $progressService): JsonResponse
    {
        $student = $request->user('student');
        abort_unless(Gate::forUser($student)->allows('viewByStudent', $lesson), 404);
        $student->loadMissing('preference');
        $progress = $progressService->save($student, $lesson, $request->validated());
        $duration = max(1, (int) $lesson->duration_seconds);

        return response()->json([
            'lesson_id' => $lesson->id,
            'position_seconds' => $progress->last_position_seconds,
            'watched_seconds' => $progress->watched_seconds,
            'duration_seconds' => $duration,
            'progress_percent' => min(100, (int) round($progress->watched_seconds / $duration * 100)),
            'completed' => $progress->completed_at !== null,
            'completed_at' => $progress->completed_at?->toIso8601String(),
            'updated_at' => $progress->updated_at?->toIso8601String(),
        ]);
    }
}
