<?php

namespace App\Console\Commands;

use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Modules\Automation\Services\WorkflowEngine;
use Illuminate\Console\Command;

/**
 * Drives the time-based side of automations across all tenants:
 *   1. resumes runs parked on a timed "wait" node whose delay has elapsed;
 *   2. (lightweight) fires "scheduled" workflows that are due.
 *
 * Needs the Hostinger cron: * * * * * php /path/artisan schedule:run
 */
class RunWorkflows extends Command
{
    protected $signature = 'workflows:run';
    protected $description = 'Resume waiting workflow runs and start scheduled ones';

    public function handle(WorkflowEngine $engine): int
    {
        // 1) Resume elapsed timed waits (never the ones waiting for a reply).
        WorkflowExecution::withoutGlobalScopes()
            ->where('status', 'waiting')
            ->where('waiting_for_reply', false)
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', now())
            ->with('workflow.nodes', 'workflow.edges')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (WorkflowExecution $execution) use ($engine) {
                $this->info("Resuming workflow run {$execution->uuid}");
                $engine->resume($execution);
            });

        // 2) Fire due "scheduled" workflows (run_at in trigger_config, ISO-8601).
        Workflow::withoutGlobalScopes()
            ->where('status', 'active')
            ->where('trigger_type', 'scheduled')
            ->with(['nodes', 'edges'])
            ->get()
            ->each(function (Workflow $workflow) use ($engine) {
                $runAt = $workflow->trigger_config['run_at'] ?? null;
                if (! $runAt) {
                    return;
                }
                try {
                    if (now()->lt(\Carbon\Carbon::parse($runAt))) {
                        return;
                    }
                } catch (\Throwable) {
                    return;
                }

                // Fire once: mark it paused after launching so it doesn't repeat.
                $contacts = \App\Models\Contact::withoutGlobalScopes()
                    ->where('tenant_id', $workflow->tenant_id)
                    ->limit(500)
                    ->get();

                foreach ($contacts as $contact) {
                    $engine->startExecution($workflow, $contact, null, ['trigger' => 'scheduled']);
                }

                $workflow->update(['status' => 'paused']);
                $this->info("Ran scheduled workflow {$workflow->uuid} for {$contacts->count()} contacts");
            });

        return self::SUCCESS;
    }
}
