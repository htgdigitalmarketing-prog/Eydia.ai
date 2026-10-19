<?php

namespace App\Modules\Automation\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappPhoneNumber;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowExecutionLog;
use App\Models\WorkflowNode;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Runs a saved workflow against one contact: walks the node graph from the entry
 * trigger, performing each action / evaluating each condition, and pausing on wait
 * nodes (timed) or wait-for-reply nodes. A paused run is picked up again by
 * {@see resume()} — from the scheduler (timed waits) or the webhook (replies).
 *
 * Every step is wrapped so one bad node never throws out of message intake; the
 * failure is logged on the execution and the run stops cleanly.
 */
class WorkflowEngine
{
    /** Hard ceiling on nodes visited in a single pass — stops accidental loops. */
    private const MAX_STEPS = 100;

    public function __construct(private readonly WhatsAppMessageService $messages) {}

    /**
     * Begin a fresh run at the workflow's entry (trigger) node.
     */
    public function startExecution(Workflow $workflow, Contact $contact, ?Conversation $conversation, array $context = []): ?WorkflowExecution
    {
        $entry = $workflow->nodes()->where('is_entry', true)->first();
        if (! $entry) {
            return null;
        }

        $execution = WorkflowExecution::create([
            'tenant_id' => $workflow->tenant_id,
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
            'conversation_id' => $conversation?->id,
            'status' => 'running',
            'current_node_key' => $entry->node_key,
            'context' => $context,
            'started_at' => now(),
        ]);

        $this->log($execution, $entry->node_key, 'trigger', 'started', 'Workflow started');

        $this->run($execution, $workflow, $entry);

        return $execution;
    }

    /**
     * Continue a paused run. `$extra` merges into the stored context (e.g. the
     * customer's reply text for a wait-for-reply node).
     */
    public function resume(WorkflowExecution $execution, array $extra = []): void
    {
        if (! in_array($execution->status, ['waiting', 'running'], true)) {
            return;
        }

        $workflow = $execution->workflow;
        if (! $workflow) {
            return;
        }

        if ($extra) {
            $execution->update(['context' => array_merge($execution->context ?? [], $extra)]);
        }

        $execution->update(['status' => 'running', 'waiting_for_reply' => false, 'resume_at' => null]);

        $current = $workflow->nodes()->where('node_key', $execution->current_node_key)->first();
        if (! $current) {
            $this->finish($execution, 'completed');

            return;
        }

        // A wait / wait-for-reply node has now elapsed — move past it.
        $this->run($execution, $workflow, $current, advancePastCurrent: true);
    }

    /**
     * Walk the graph forward until we hit a pause (wait) or run out of edges.
     */
    private function run(WorkflowExecution $execution, Workflow $workflow, WorkflowNode $node, bool $advancePastCurrent = false): void
    {
        $nodes = $workflow->nodes->keyBy('node_key');
        $edges = $workflow->edges;

        $current = $node;
        $steps = 0;

        // When resuming from a wait node, we already ran it — step to its next node.
        if ($advancePastCurrent) {
            $current = $this->firstNext($nodes, $edges, $current->node_key);
            if (! $current) {
                $this->finish($execution, 'completed');

                return;
            }
        }

        while ($current && $steps++ < self::MAX_STEPS) {
            $execution->update(['current_node_key' => $current->node_key]);

            // ---- Wait nodes: pause the run and return control. ----
            if ($current->type === 'wait') {
                $minutes = max(1, (int) ($current->config['minutes'] ?? 5));
                $execution->update([
                    'status' => 'waiting',
                    'resume_at' => now()->addMinutes($minutes),
                    'waiting_for_reply' => false,
                ]);
                $this->log($execution, $current->node_key, 'wait', 'waiting', "Waiting {$minutes} min");

                return;
            }

            if ($current->type === 'wait_for_reply') {
                $execution->update(['status' => 'waiting', 'waiting_for_reply' => true, 'resume_at' => null]);
                $this->log($execution, $current->node_key, 'wait_for_reply', 'waiting', 'Waiting for customer reply');

                return;
            }

            // ---- Conditions: evaluate and pick a branch. ----
            if ($current->family === 'condition') {
                $result = $this->evaluateCondition($current, $execution);
                $this->log($execution, $current->node_key, $current->type, 'condition', $result ? 'Condition TRUE' : 'Condition FALSE');
                $current = $this->branchNext($nodes, $edges, $current->node_key, $result);
                continue;
            }

            // ---- Actions (and the entry trigger, which is a no-op pass-through). ----
            if ($current->family === 'action') {
                $this->runAction($current, $execution);
            }

            $current = $this->firstNext($nodes, $edges, $current->node_key);
        }

        $this->finish($execution, 'completed');
    }

    /** Perform one action node. Never throws — failures are logged and the run continues. */
    private function runAction(WorkflowNode $node, WorkflowExecution $execution): void
    {
        $contact = $execution->contact;
        if (! $contact) {
            return;
        }
        $config = $node->config ?? [];

        try {
            switch ($node->type) {
                case 'send_message':
                    $this->sendText($execution, $contact, (string) ($config['message'] ?? ''));
                    break;

                case 'send_template':
                    $this->sendTemplate($execution, $contact, (string) ($config['template_name'] ?? ''), (string) ($config['language'] ?? 'en'));
                    break;

                case 'add_tag':
                    $this->setTag($contact, (string) ($config['tag'] ?? ''), add: true);
                    $this->log($execution, $node->node_key, $node->type, 'action', "Added tag: {$config['tag']}");
                    break;

                case 'remove_tag':
                    $this->setTag($contact, (string) ($config['tag'] ?? ''), add: false);
                    $this->log($execution, $node->node_key, $node->type, 'action', "Removed tag: {$config['tag']}");
                    break;

                case 'assign_agent':
                    $this->assignAgent($execution, (string) ($config['agent_id'] ?? $config['agent_email'] ?? ''));
                    break;

                case 'update_contact':
                    $this->updateContact($contact, $config);
                    $this->log($execution, $node->node_key, $node->type, 'action', 'Contact updated');
                    break;

                case 'webhook':
                    $this->fireWebhook($execution, $contact, (string) ($config['url'] ?? ''));
                    $this->log($execution, $node->node_key, $node->type, 'action', 'Webhook sent');
                    break;
            }
        } catch (\Throwable $e) {
            $this->log($execution, $node->node_key, $node->type, 'error', 'Action failed: ' . $e->getMessage());
        }
    }

    private function evaluateCondition(WorkflowNode $node, WorkflowExecution $execution): bool
    {
        $contact = $execution->contact;
        $config = $node->config ?? [];

        if ($node->type === 'condition_has_tag') {
            $tag = mb_strtolower(trim((string) ($config['tag'] ?? '')));

            return $tag !== '' && $contact
                && collect($contact->tagNames())->contains(fn ($t) => mb_strtolower($t) === $tag);
        }

        if ($node->type === 'condition_keyword') {
            $needle = mb_strtolower(trim((string) ($config['keyword'] ?? '')));
            $haystack = mb_strtolower((string) ($execution->context['last_reply'] ?? $execution->context['body'] ?? ''));

            return $needle !== '' && str_contains($haystack, $needle);
        }

        return false;
    }

    // --- Action implementations -------------------------------------------------

    private function sendText(WorkflowExecution $execution, Contact $contact, string $body): void
    {
        $body = $this->fillPlaceholders($body, $contact);
        if ($body === '') {
            return;
        }
        $phone = $this->phoneFor($execution);
        if (! $phone) {
            $this->log($execution, null, 'send_message', 'error', 'No WhatsApp number connected');

            return;
        }

        $result = $this->messages->sendText($phone, $contact->wa_id, $body);
        $this->recordOutbound($execution, $contact, $phone, $body, 'text', $result);
        $this->log($execution, null, 'send_message', 'action', 'Message sent');
    }

    private function sendTemplate(WorkflowExecution $execution, Contact $contact, string $template, string $language): void
    {
        if ($template === '') {
            return;
        }
        $phone = $this->phoneFor($execution);
        if (! $phone) {
            $this->log($execution, null, 'send_template', 'error', 'No WhatsApp number connected');

            return;
        }

        $result = $this->messages->sendTemplate($phone, $contact->wa_id, $template, $language ?: 'en');
        $body = \App\Models\Template::bodyText($execution->tenant_id, $template) ?: "📄 Template: {$template}";
        $this->recordOutbound($execution, $contact, $phone, $body, 'template', $result);
        $this->log($execution, null, 'send_template', 'action', "Template sent: {$template}");
    }

    private function setTag(Contact $contact, string $tag, bool $add): void
    {
        $tag = trim($tag);
        if ($tag === '') {
            return;
        }
        $tags = $contact->tagNames();
        $has = collect($tags)->contains(fn ($t) => mb_strtolower($t) === mb_strtolower($tag));

        if ($add && ! $has) {
            $tags[] = $tag;
        } elseif (! $add && $has) {
            $tags = collect($tags)->reject(fn ($t) => mb_strtolower($t) === mb_strtolower($tag))->values()->all();
        } else {
            return;
        }

        $contact->update(['tag_list' => implode(', ', $tags)]);
    }

    private function assignAgent(WorkflowExecution $execution, string $agentRef): void
    {
        $agentRef = trim($agentRef);
        $conversation = $execution->conversation;
        if ($agentRef === '' || ! $conversation) {
            return;
        }

        $agent = \App\Models\User::withoutGlobalScopes()
            ->where('tenant_id', $execution->tenant_id)
            ->where(fn ($q) => $q->where('uuid', $agentRef)->orWhere('email', $agentRef))
            ->first();

        if ($agent) {
            $conversation->update(['assigned_agent_id' => $agent->id]);
            $this->log($execution, null, 'assign_agent', 'action', "Assigned to {$agent->name}");
        }
    }

    private function updateContact(Contact $contact, array $config): void
    {
        $allowed = array_intersect_key($config, array_flip(['name', 'email', 'company', 'language', 'country']));
        if ($allowed) {
            $contact->update($allowed);
        }
    }

    private function fireWebhook(WorkflowExecution $execution, Contact $contact, string $url): void
    {
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            return;
        }

        Http::timeout(8)->post($url, [
            'contact' => [
                'id' => $contact->uuid,
                'name' => $contact->name,
                'phone' => $contact->phone,
                'wa_id' => $contact->wa_id,
                'tags' => $contact->tagNames(),
            ],
            'context' => $execution->context,
            'workflow_execution' => $execution->uuid,
        ]);
    }

    // --- Helpers ----------------------------------------------------------------

    private function fillPlaceholders(string $text, Contact $contact): string
    {
        return str_replace(
            ['{{name}}', '{{phone}}'],
            [$contact->name ?: 'there', $contact->phone ?: ''],
            $text
        );
    }

    private function phoneFor(WorkflowExecution $execution): ?WhatsappPhoneNumber
    {
        if ($execution->conversation && $execution->conversation->whatsapp_phone_number_id) {
            $phone = WhatsappPhoneNumber::withoutGlobalScopes()->find($execution->conversation->whatsapp_phone_number_id);
            if ($phone) {
                return $phone;
            }
        }

        return WhatsappPhoneNumber::withoutGlobalScopes()
            ->where('tenant_id', $execution->tenant_id)
            ->orderByDesc('is_default')
            ->first();
    }

    private function recordOutbound(WorkflowExecution $execution, Contact $contact, WhatsappPhoneNumber $phone, string $body, string $type, array $result): void
    {
        $conversation = $execution->conversation;
        if (! $conversation) {
            $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
                ['contact_id' => $contact->id, 'whatsapp_phone_number_id' => $phone->id],
                ['tenant_id' => $execution->tenant_id, 'status' => 'open', 'unread_count' => 0, 'is_bot_active' => false],
            );
            $execution->update(['conversation_id' => $conversation->id]);
        }

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $execution->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phone->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => $type,
            'body' => $body,
            'external_message_id' => $this->messages->wamid($result),
            'status' => 'sent',
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($body, 100),
        ]);
    }

    /** Next node following the first non-branch (default) outgoing edge. */
    private function firstNext($nodes, $edges, string $fromKey): ?WorkflowNode
    {
        $edge = $edges->first(fn ($e) => $e->source_node_key === $fromKey);

        return $edge ? $nodes->get($edge->target_node_key) : null;
    }

    /**
     * Pick the next node after a condition. If the condition's edges carry yes/no
     * (or true/false) labels, follow the matching one. If they're all unlabeled,
     * the condition acts as a gate: TRUE continues, FALSE ends the branch.
     */
    private function branchNext($nodes, $edges, string $fromKey, bool $result): ?WorkflowNode
    {
        $out = $edges->filter(fn ($e) => $e->source_node_key === $fromKey);

        $wantYes = ['yes', 'true'];
        $wantNo = ['no', 'false'];
        $labeled = $out->first(fn ($e) => in_array(strtolower((string) $e->branch), array_merge($wantYes, $wantNo), true));

        if ($labeled) {
            $want = $result ? $wantYes : $wantNo;
            $edge = $out->first(fn ($e) => in_array(strtolower((string) $e->branch), $want, true));

            return $edge ? $nodes->get($edge->target_node_key) : null;
        }

        // No explicit branches — gate on TRUE.
        if (! $result) {
            return null;
        }

        $edge = $out->first();

        return $edge ? $nodes->get($edge->target_node_key) : null;
    }

    private function finish(WorkflowExecution $execution, string $status): void
    {
        $execution->update(['status' => $status, 'finished_at' => now()]);
        $this->log($execution, $execution->current_node_key, null, $status, 'Workflow ' . $status);
    }

    private function log(WorkflowExecution $execution, ?string $nodeKey, ?string $nodeType, string $event, string $message): void
    {
        WorkflowExecutionLog::create([
            'tenant_id' => $execution->tenant_id,
            'workflow_execution_id' => $execution->id,
            'node_key' => $nodeKey,
            'node_type' => $nodeType,
            'event' => $event,
            'message' => $message,
            'occurred_at' => now(),
        ]);
    }
}
