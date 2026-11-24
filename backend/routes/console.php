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

// SAFETY-NET queue drainer: drains the (database) queue at least once a minute,
// so a job is NEVER stuck longer than ~60s even if the fast worker below is down.
// --stop-when-empty exits the instant the queue is clear (so it costs nothing
// when the dedicated worker is already keeping up), and withoutOverlapping stops
// two of these piling up.
//
// For NEAR-INSTANT chatbot replies (1-2s instead of up to a minute) a dedicated
// long-lived worker is kept alive by a SEPARATE Hostinger cron, independent of
// this scheduler, because the host kills Laravel's runInBackground() processes:
//
//   * * * * * flock -n /home/<user>/domains/eydia.in/app/storage/queue.lock \
//       -c '/usr/bin/php /home/<user>/domains/eydia.in/app/artisan \
//           queue:work --sleep=1 --tries=3 --max-time=3600 --max-jobs=1000' \
//       >> /dev/null 2>&1
//
// flock -n keeps exactly ONE worker alive (process-cap safe) and the OS frees the
// lock the moment the worker dies — clean or killed — so the next minute's cron
// restarts it. That worker polls every second, so inbound messages are picked up
// almost immediately; this scheduler entry is only the fallback.
Schedule::command('queue:work --stop-when-empty --max-time=50 --max-jobs=200 --tries=3 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping();
