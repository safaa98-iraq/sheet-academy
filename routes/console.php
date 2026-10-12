<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:prune-student-document-pages')->daily()->withoutOverlapping();

Schedule::command('app:notify-published-lectures')->everyMinute()->withoutOverlapping(10);
Schedule::command('app:notify-expired-student-access')->everyMinute()->withoutOverlapping(10);

Schedule::command('app:notify-upcoming-subscriptions')->everyMinute()->withoutOverlapping(10);
