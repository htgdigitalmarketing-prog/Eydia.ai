<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Publish scheduled Facebook/Instagram posts when their time arrives.
// Requires Hostinger cron: * * * * * php /path/artisan schedule:run
Schedule::command('social:publish-due')->everyMinute()->withoutOverlapping();

// Finish bulk sends / campaigns whose web worker was killed mid-way.
Schedule::command('whatsapp:resume-sends')->everyMinute()->withoutOverlapping(30);

// Nudge quiet customers (two follow-ups, at +6h and +12h).
Schedule::command('whatsapp:send-followups')->everyTenMinutes()->withoutOverlapping(20);

// Drive automations: resume elapsed timed waits and fire scheduled workflows.
Schedule::command('workflows:run')->everyMinute()->withoutOverlapping();
