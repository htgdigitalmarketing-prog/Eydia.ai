<?php

namespace App\Console\Commands;

use App\Models\BulkSendRecipient;
use App\Models\WebhookEvent;
use App\Modules\WhatsApp\Services\BulkSendService;
use Illuminate\Console\Command;

/**
 * Replays stored WhatsApp status webhooks so PAST bulk-send numbers match Meta.
 *
 * The live path now reconciles bulk recipients from status webhooks, but sends
 * made BEFORE that fix left recipients stuck at "sent" (cold/bulk numbers whose
 * delivered/failed callbacks were dropped). Those callbacks were still stored in
 * `webhook_events` (object_type='status'), so we can replay them through the
 * same idempotent reconcile.
 *
 * (Analytics needs no replay — its funnel already reads the message_statuses
 * event log directly, so history is corrected the moment that code ships.)
 *
 * SAFE BY DESIGN: dry-run by default; pass --apply to write. Idempotent — a
 * delivered recipient is never dragged back to failed.
 */
class ReconcileBulkStatus extends Command
{
    protected $signature = 'bulk:reconcile-status {--days=60 : Replay stored status webhooks from the last N days} {--apply : Actually apply (otherwise dry-run)}';

    protected $description = 'Replay stored WhatsApp status webhooks so past bulk-send Sent/Delivered/Failed match Meta';

    public function handle(BulkSendService $bulk): int
    {
        $days = max(1, (int) $this->option('days'));
        $apply = (bool) $this->option('apply');
        $since = now()->subDays($days);

        $scanned = 0;
        $matched = 0;
        $changed = 0;
        $affectedSends = [];

        WebhookEvent::query()
            ->where('source', 'whatsapp')
            ->where('object_type', 'status')
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->chunkById(500, function ($events) use (&$scanned, &$matched, &$changed, &$affectedSends, $apply, $bulk) {
                foreach ($events as $event) {
                    $scanned++;
                    $status = $event->payload['status'] ?? [];
                    $wamid = $status['id'] ?? null;
                    $statusName = $status['status'] ?? null;
                    if (! $wamid || ! $statusName) {
                        continue;
                    }

                    $recipient = BulkSendRecipient::where('wamid', $wamid)->first();
                    if (! $recipient) {
                        continue;
                    }
                    $matched++;

                    $target = $this->targetStatus($recipient->status, $statusName);
                    if ($target === null || $target === $recipient->status) {
                        continue;
                    }

                    $changed++;
                    $affectedSends[$recipient->bulk_send_id] = true;

                    if ($apply) {
                        $bulk->syncRecipientStatus(
                            $wamid,
                            $statusName,
                            $status['errors'][0]['title'] ?? null,
                            isset($status['errors'][0]['code']) ? (string) $status['errors'][0]['code'] : null,
                        );
                    }
                }
            });

        $this->info(sprintf(
            'Scanned %d status events · %d matched bulk recipients · %d %s · %d bulk send(s) affected.',
            $scanned,
            $matched,
            $changed,
            $apply ? 'updated' : 'would change',
            count($affectedSends),
        ));

        if (! $apply && $changed > 0) {
            $this->comment('Dry-run — re-run with --apply to write these changes.');
        }

        return self::SUCCESS;
    }

    /** The status a recipient should move to for a given event (mirrors syncRecipientStatus). */
    private function targetStatus(string $current, string $event): ?string
    {
        if ($current === 'delivered') {
            return null; // terminal — never downgraded by a late/duplicate event
        }
        if (in_array($event, ['delivered', 'read'], true)) {
            return 'delivered';
        }
        if ($event === 'failed') {
            return 'failed';
        }

        return null; // sent / queued — already reflected
    }
}
