<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Console\Command;

/**
 * Removes "cold broadcast" chats: conversations that only ever received outbound
 * bulk/campaign messages and never a single reply from the customer. These were
 * auto-created for every bulk recipient (including unreachable numbers), which
 * flooded the inbox and — because Meta reports those sends as "failed" — inflated
 * the Analytics failed count.
 *
 * SAFE BY DESIGN:
 *   - Only touches conversations with ZERO inbound messages (a real chat always
 *     has at least one inbound, created by the webhook).
 *   - Dry-run by default; pass --apply to actually delete.
 *   - Scope to one tenant with --tenant=<id>.
 *   - Contacts are KEPT (your mailing list stays intact); only the empty cold
 *     chats and their outbound messages are removed.
 */
class CleanupColdBroadcasts extends Command
{
    protected $signature = 'whatsapp:cleanup-broadcasts {--tenant= : Only this tenant id} {--apply : Actually delete (otherwise dry-run)} {--force : Skip the confirmation prompt}';
    protected $description = 'Delete cold broadcast chats (no inbound ever) that pollute the inbox and analytics';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $tenant = $this->option('tenant');

        // Conversations that never received an inbound message.
        $query = Conversation::withoutGlobalScopes()
            ->whereDoesntHave('messages', fn ($q) => $q->where('direction', Message::DIRECTION_INBOUND));

        if ($tenant) {
            $query->where('tenant_id', $tenant);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No cold broadcast chats found. Nothing to clean.');

            return self::SUCCESS;
        }

        $convIds = (clone $query)->pluck('id');

        $msgQuery = Message::withoutGlobalScopes()->whereIn('conversation_id', $convIds);
        $msgCount = (clone $msgQuery)->count();
        $failedCount = (clone $msgQuery)->where('status', 'failed')->count();

        $this->line('');
        $this->info($apply ? 'Deleting cold broadcast chats…' : 'DRY RUN — nothing will be deleted. Pass --apply to execute.');
        $this->table(['What', 'Count'], [
            ['Cold conversations (0 inbound)', $total],
            ['Messages inside them', $msgCount],
            ['…of which marked failed', $failedCount],
        ]);

        if (! $apply) {
            $this->line('');
            $this->comment('Re-run with --apply to remove them. Contacts are kept.');

            return self::SUCCESS;
        }

        // Guard against an accidental mass delete. Soft-delete is reversible via
        // whatsapp:restore-broadcasts, but confirm anyway unless --force is given.
        if (! $this->option('force') && ! $this->confirm("Soft-delete {$total} chats and {$msgCount} messages? (restorable with whatsapp:restore-broadcasts)")) {
            $this->comment('Cancelled. Nothing was deleted.');

            return self::SUCCESS;
        }

        $deletedMsgs = 0;
        // Soft-delete in chunks so a huge backlog doesn't exhaust memory. Both
        // Message and Conversation use SoftDeletes, so this is reversible and the
        // rows immediately drop out of analytics and the inbox.
        $convIds->chunk(500)->each(function ($chunk) use (&$deletedMsgs) {
            $deletedMsgs += Message::withoutGlobalScopes()->whereIn('conversation_id', $chunk)->delete();
            Conversation::withoutGlobalScopes()->whereIn('id', $chunk)->delete();
        });

        $this->info("Done. Removed {$total} chats and {$deletedMsgs} messages ({$failedCount} were failed). Contacts kept.");

        return self::SUCCESS;
    }
}
