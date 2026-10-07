<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LessonProgressService
{
    /** @param array{event: string, position_seconds: int, is_playing: bool} $data */
    public function save(Student $student, Lesson $lesson, array $data): LessonProgress
    {
        $saved = DB::transaction(function () use ($student, $lesson, $data): LessonProgress {
            $progress = LessonProgress::query()->firstOrCreate(
                ['student_id' => $student->id, 'lesson_id' => $lesson->id],
                ['last_position_seconds' => 0, 'watched_seconds' => 0, 'is_playing' => false],
            );
            $progress = LessonProgress::query()->whereKey($progress->id)->lockForUpdate()->firstOrFail();
            $duration = max(1, (int) $lesson->duration_seconds);
            $position = min($duration, (int) $data['position_seconds']);
            $elapsed = $progress->is_playing && $progress->last_heartbeat_at !== null
                ? min((int) config('learning.heartbeat_max_gap_seconds', 25), max(0, (int) $progress->last_heartbeat_at->diffInSeconds(now())))
                : 0;
            $advanced = $position - (int) $progress->last_position_seconds;
            $playbackRate = min(2, max(0.5, (float) ($student->preference?->playback_speed ?? 1)));
            $maximumAdvance = (int) floor($elapsed * $playbackRate);
            $creditedSeconds = $data['event'] !== 'seeked' && $advanced > 0 && $advanced <= $maximumAdvance + 3
                ? min($advanced, $maximumAdvance)
                : 0;
            $watchedSeconds = min($duration, (int) $progress->watched_seconds + $creditedSeconds);
            $threshold = min(1, max(0.5, (float) config('learning.completion_threshold', 0.9)));
            $completedAt = $progress->completed_at;
            if ($completedAt === null && $watchedSeconds >= (int) ceil($duration * $threshold)) {
                $completedAt = now();
            }

            $progress->forceFill([
                'last_position_seconds' => $position,
                'watched_seconds' => $watchedSeconds,
                'completed_at' => $completedAt,
                'last_heartbeat_at' => now(),
                'is_playing' => (bool) $data['is_playing'],
            ])->save();

            return $progress->refresh();
        }, attempts: 3);

        Cache::forget('admin.student-progress.stats.v1');

        return $saved;
    }
}
