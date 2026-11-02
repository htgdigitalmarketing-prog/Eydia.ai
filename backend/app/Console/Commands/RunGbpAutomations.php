<?php

namespace App\Console\Commands;

use App\Models\GbpAutomation;
use App\Modules\Gbp\Services\GbpAutomationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs the enabled Google Business Profile automations for every tenant:
 *   • auto_review_replies — AI-reply to unanswered reviews (and post when a live
 *     Google connection exists);
 *   • auto_posts — keep the scheduled-post queue topped up at the chosen cadence,
 *     optionally with an AI image when auto_photos is on.
 *
 * The actual per-tenant work lives in GbpAutomationRunner so the "Run now" button
 * on the Automation page does exactly the same thing. Scheduled hourly — this
 * needs the Hostinger cron:  * * * * * php /path/artisan schedule:run
 */
class RunGbpAutomations extends Command
{
    protected $signature = 'gbp:run-automations';
    protected $description = 'Run enabled GBP automations (AI review replies, scheduled posts)';

    public function handle(GbpAutomationRunner $runner): int
    {
        $rows = GbpAutomation::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('auto_review_replies', true)->orWhere('auto_posts', true))
            ->get();

        foreach ($rows as $automation) {
            try {
                $runner->run($automation);
            } catch (\Throwable $e) {
                Log::warning('gbp automation failed for tenant ' . $automation->tenant_id . ': ' . $e->getMessage());
            }
        }

        $this->info('GBP automations run for ' . $rows->count() . ' tenant(s).');

        return self::SUCCESS;
    }
}
