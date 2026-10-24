<?php

namespace App\Console\Commands;

use App\Modules\Chatbot\Services\LeadFlowAutomation;
use Illuminate\Console\Command;

/**
 * Sends the GBP Lead Automation's due drip follow-ups (24h nudge, 3-day drip,
 * 2-day info follow-up, 14-day cold re-engagement, pre-demo reminder) across
 * all tenants. Scheduled every few minutes (see routes/console.php).
 *
 * Needs the Hostinger cron: * * * * * php /path/artisan schedule:run
 */
class RunLeadFlowAutomation extends Command
{
    protected $signature = 'chatbot:lead-flow-run';
    protected $description = 'Send due GBP Lead Automation drip follow-ups';

    public function handle(LeadFlowAutomation $automation): int
    {
        $sent = $automation->sendDue();
        $this->info("Sent {$sent} lead-flow follow-up(s).");

        return self::SUCCESS;
    }
}
