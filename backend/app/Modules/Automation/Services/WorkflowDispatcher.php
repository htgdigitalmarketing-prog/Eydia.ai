<?php

namespace App\Modules\Automation\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Workflow;
use App\Models\WorkflowExecution;

/**
 * The seam between the rest of the app and the workflow engine. Call {@see fire()}
 * when something happens (a reply arrives, a contact is created, a tag changes) and
 * it starts every matching active workflow for that tenant. All calls are
 * best-effort: a failure here must never break message intake, so callers wrap it.
 */
class WorkflowDispatcher
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /**
     * Start every active workflow whose trigger matches.
     *
     * @param  string  $triggerType  new_contact | incoming_message | keyword | tag_added | tag_removed
     * @param  array   $context      e.g. ['body' => '...', 'tag' => '...']
     */
    public function fire(int $tenantId, string $triggerType, Contact $contact, ?Conversation $conversation, array $context = []): void
    {
        $workflows = Workflow::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->with(['nodes', 'edges'])
            ->get();

        foreach ($workflows as $workflow) {
            if (! $this->triggerMatches($workflow, $triggerType, $context)) {
                continue;
            }

            // Don't stack a second run of the same workflow on a contact while one
            // is still in flight (prevents loops from repeated inbound messages).
            $alreadyRunning = WorkflowExecution::withoutGlobalScopes()
                ->where('workflow_id', $workflow->id)
                ->where('contact_id', $contact->id)
                ->whereIn('status', ['running', 'waiting'])
                ->exists();

            if ($alreadyRunning) {
                continue;
            }

            $this->engine->startExecution($workflow, $contact, $conversation, array_merge($context, ['trigger' => $triggerType]));
        }
    }

    /**
     * A customer replied — resume any of their runs that were parked on a
     * "wait for reply" node.
     */
    public function resumeOnReply(Contact $contact, ?Conversation $conversation, string $body): void
    {
        $executions = WorkflowExecution::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('status', 'waiting')
            ->where('waiting_for_reply', true)
            ->with('workflow.nodes', 'workflow.edges')
            ->get();

        foreach ($executions as $execution) {
            $this->engine->resume($execution, ['last_reply' => $body]);
        }
    }

    /** Keyword triggers only fire when the inbound text contains the configured word. */
    private function triggerMatches(Workflow $workflow, string $triggerType, array $context): bool
    {
        if ($triggerType === 'keyword') {
            $keyword = mb_strtolower(trim((string) ($workflow->trigger_config['keyword'] ?? '')));
            $body = mb_strtolower((string) ($context['body'] ?? ''));

            return $keyword !== '' && str_contains($body, $keyword);
        }

        if (in_array($triggerType, ['tag_added', 'tag_removed'], true)) {
            $want = mb_strtolower(trim((string) ($workflow->trigger_config['tag'] ?? '')));
            // No tag configured = match any tag change; otherwise it must match.
            return $want === '' || $want === mb_strtolower(trim((string) ($context['tag'] ?? '')));
        }

        return true;
    }
}
