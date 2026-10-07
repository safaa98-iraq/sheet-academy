<?php

return [
    'completion_threshold' => (float) env('LESSON_COMPLETION_THRESHOLD', 0.9),
    'heartbeat_seconds' => (int) env('LESSON_PROGRESS_HEARTBEAT_SECONDS', 12),
    'heartbeat_max_gap_seconds' => (int) env('LESSON_PROGRESS_MAX_HEARTBEAT_GAP_SECONDS', 25),
];
