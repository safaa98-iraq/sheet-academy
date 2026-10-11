<?php

namespace App\Notifications;

use App\Models\Lesson;
use Illuminate\Notifications\Notification;

class NewLecturePublished extends Notification
{
    public function __construct(public Lesson $lesson) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['lesson_id' => $this->lesson->id, 'course_id' => $this->lesson->course_id, 'title' => $this->lesson->title, 'course_title' => $this->lesson->course->title];
    }
}
