<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Console\Command;

/**
 * Undoes whatsapp:cleanup-broadcasts. Because that command SOFT-deletes (it only
 * sets deleted_at), every chat and message it removed is still in the database and
 * can be brought back.
 *
 * Restores conversations soft-deleted within a recent window (default 24h, so it
 * targets a cleanup you just ran) and the messages inside them.
 *
 *   php artisan whatsapp:restore-broadcasts            # dry-run: shows what would return
 *   php artisan whatsapp:restore-broadcasts --apply    # actually restore
 *   php artisan whatsapp:restore-broadcasts --apply --minutes=2880   # last 2 days
 *   php artisan whatsapp:restore-broadcasts --apply --tenant=13
 */
class RestoreBroadcasts extends Command
{
    protected $signature = 'whatsapp:restore-broadcasts {--tenant= : Only this tenant id} {--minutes=1440 : Restore items deleted within the last N minutes} {--apply : Actually restore (otherwise dry-run)}';
    protected $description = 'Restore chats removed by whatsapp:cleanup-broadcasts (they were only soft-deleted)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $tenant = $this->option('tenant');
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $convQuery = Conversation::withoutGlobalScopes()->onlyTrashed()->where('deleted_at', '>=', $cutoff);
        if ($tenant) {
            $convQuery->where('tenant_id', $tenant);
        }

        $convIds = (clone $convQuery)->pluck('id');
        $convCount = $convIds->count();

        if ($convCount === 0) {
            $this->info("No soft-deleted chats found in the last {$minutes} minutes. Nothing to restore.");

            return self::SUCCESS;
        }

        $msgCount = Message::withoutGlobalScopes()->onlyTrashed()->whereIn('conversation_id', $convIds)->count();

        $this->line('');
        $this->info($apply ? 'Restoring chats…' : 'DRY RUN — nothing will be restored. Pass --apply to execute.');
        $this->table(['What', 'Count'], [
            ["Chats deleted in last {$minutes} min", $convCount],
            ['Messages inside them', $msgCount],
        ]);

        if (! $apply) {
            $this->comment('Re-run with --apply to bring them back.');

            return self::SUCCESS;
        }

        $restoredMsgs = 0;
        $convIds->chunk(500)->each(function ($chunk) use (&$restoredMsgs) {
            $restoredMsgs += Message::withoutGlobalScopes()->onlyTrashed()->whereIn('conversation_id', $chunk)->restore();
            Conversation::withoutGlobalScopes()->onlyTrashed()->whereIn('id', $chunk)->restore();
        });

        $this->info("Done. Restored {$convCount} chats and {$restoredMsgs} messages.");

        return self::SUCCESS;
    }
}
