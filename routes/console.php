<?php

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
