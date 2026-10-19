<?php

namespace App\Console\Commands;

use App\Models\BulkSend;
use App\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignSendService;
use App\Modules\WhatsApp\Services\BulkSendService;
use Illuminate\Console\Command;

/**
 * Finishes bulk sends and campaigns whose web-request worker was killed by the
 * host. Runs across all tenants (console context has no tenant scope).
 */
class ResumeStalledSends extends Command
{
    protected $signature = 'whatsapp:resume-sends';
    protected $description = 'Resume bulk sends and campaigns that stopped mid-way';

    public function handle(BulkSendService $bulk, CampaignSendService $campaigns): int
    {
        BulkSend::withoutGlobalScopes()
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subSeconds(BulkSendService::STALE_SECONDS))
            ->orderBy('id')
            ->get()
            ->each(function (BulkSend $send) use ($bulk) {
                if ($bulk->claimIfStalled($send)) {
                    $this->info("Resuming bulk send {$send->uuid}");
                    $bulk->process($send);
                }
            });

        Campaign::withoutGlobalScopes()
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subSeconds(CampaignSendService::STALE_SECONDS))
            ->orderBy('id')
            ->get()
            ->each(function (Campaign $campaign) use ($campaigns) {
                if ($campaigns->claimIfStalled($campaign)) {
                    $this->info("Resuming campaign {$campaign->uuid}");
                    $campaigns->process($campaign);
                }
            });

        return self::SUCCESS;
    }
}
