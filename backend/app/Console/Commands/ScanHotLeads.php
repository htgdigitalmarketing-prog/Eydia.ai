<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Modules\Chatbot\Services\HotLeadDetector;
use Illuminate\Console\Command;

/**
 * One-time (or occasional) catch-up: lets the AI look at recent existing chats
 * and add the "Hot" tag + priority score to customers who showed interest before
 * hot-lead detection was switched on.
 *
 *   php artisan hotlist:scan                 (last 30 days, up to 200 chats)
 *   php artisan hotlist:scan --days=90 --limit=500
 *   php artisan hotlist:scan --tenant=1
 */
class ScanHotLeads extends Command
{
    protected $signature = 'hotlist:scan {--days=30 : Only chats active in the last N days} {--limit=200 : Max chats to check} {--tenant= : Only this tenant id}';

    protected $description = 'Let the AI scan recent chats and add interested customers to the Hot List';

    public function handle(HotLeadDetector $detector): int
    {
        $conversations = Conversation::withoutGlobalScopes()
            ->where('last_message_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->orderByDesc('last_message_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $this->info("Scanning {$conversations->count()} chats...");

        $hot = $notHot = $skipped = 0;

        foreach ($conversations as $conversation) {
            try {
                $verdict = $detector->evaluateConversation($conversation);
            } catch (\Throwable $e) {
                $this->warn("Chat {$conversation->id}: {$e->getMessage()}");
                continue;
            }

            if ($verdict === null) {
                $skipped++;
            } elseif ($verdict['hot']) {
                $hot++;
                $this->line("  HOT  chat {$conversation->id}  score {$verdict['score']}  {$verdict['reason']}");
            } else {
                $notHot++;
            }
        }

        $this->info("Done. Hot: {$hot}, not hot: {$notHot}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}