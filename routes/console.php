<?php

use App\Console\Commands\AdvanceVideoProjects;
use App\Console\Commands\ReconcileAiBilling;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs `php artisan schedule:work` (or a real cron entry calling
// `schedule:run` every minute) actually running for this to fire —
// see README's "Running it locally" / deploy checklist.
Schedule::command(ReconcileAiBilling::class)->dailyAt('03:15')->withoutOverlapping();

// Video Studio renders take minutes; this is what carries them from
// "submitted" to a finished film. runInBackground so a long stitch never
// delays the rest of the schedule.
Schedule::command(AdvanceVideoProjects::class)->everyMinute()->withoutOverlapping(30)->runInBackground();
