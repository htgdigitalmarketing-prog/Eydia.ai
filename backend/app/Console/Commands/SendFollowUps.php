<?php

namespace App\Console\Commands;

use App\Modules\Chatbot\Services\FollowUpService;
use Illuminate\Console\Command;

/**
 * Sends due customer follow-up nudges across all tenants.
 * Scheduled every ten minutes (see routes/console.php).
 */
class SendFollowUps extends Command
{
    protected $signature = 'whatsapp:send-followups';
    protected $description = 'Send automatic follow-up messages to quiet customers (6h / 12h)';

    public function handle(FollowUpService $service): int
    {
        $sent = $service->sendDue();
        $this->info("Sent {$sent} follow-up(s).");

        return self::SUCCESS;
    }
}
