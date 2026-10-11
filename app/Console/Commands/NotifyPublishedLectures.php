<?php

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\LessonPublicationService;
use Illuminate\Console\Command;

class NotifyPublishedLectures extends Command
{
    protected $signature = 'app:notify-published-lectures';

    protected $description = 'Send in-app notifications when opted-in lectures become visible and ready.';

    public function handle(LessonPublicationService $publication): int
    {
        Lesson::where('notify_students', true)->whereNull('notified_at')->visibleForStudents()
            ->chunkById(100, function ($lessons) use ($publication): void {
                foreach ($lessons as $lesson) {
                    $publication->notify($lesson);
                }
            });

        return self::SUCCESS;
    }
}
