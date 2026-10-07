<?php

return [
    'device_limit' => (int) env('STUDENT_DEVICE_LIMIT', 1),
    'alert_threshold' => (int) env('AUDIT_ALERT_THRESHOLD', 3),
    'auto_suspend_score' => (int) env('AUDIT_AUTO_SUSPEND_SCORE', 0),
    'email_enabled' => (bool) env('AUDIT_EMAIL_ENABLED', false),
    'agreement_version' => env('CONTENT_AGREEMENT_VERSION', '2026-10-v1'),
];
