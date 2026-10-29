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

// Publish scheduled Google Business Profile posts & photos when due.
Schedule::command('gbp:publish-due')->everyMinute()->withoutOverlapping();

// Finish bulk sends / campaigns whose web worker was killed mid-way.
Schedule::command('whatsapp:resume-sends')->everyMinute()->withoutOverlapping(30);

// Nudge quiet customers (two follow-ups, at +6h and +12h).
Schedule::command('whatsapp:send-followups')->everyTenMinutes()->withoutOverlapping(20);

// Drive automations: resume elapsed timed waits and fire scheduled workflows.
Schedule::command('workflows:run')->everyMinute()->withoutOverlapping();

// Send the GBP Lead Automation's due drip follow-ups (24h nudge → 3-day drip,
// 2-day info follow-up, 14-day cold re-engagement, pre-demo reminder).
Schedule::command('chatbot:lead-flow-run')->everyFiveMinutes()->withoutOverlapping(20);

// Run enabled GBP automations: AI review replies + keep the post queue topped up.
Schedule::command('gbp:run-automations')->hourly()->withoutOverlapping(30);
