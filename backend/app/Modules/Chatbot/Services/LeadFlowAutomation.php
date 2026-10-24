<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadFlowTask;
use App\Models\Message;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The time-based half of the GBP Lead Automation funnel (Eydia marketing/lead
 * doc, section 3). The keyword rules handle the instant reply to a tapped
 * button; this service handles everything that happens *later* with no human:
 *
 *   • Qualified, then silent  → nudge at +24h, long-term drip at +3d
 *   • Info Sent — Nurture     → "did you get a chance to look?" at +2d
 *   • Cold / Not now          → re-engagement at +14d
 *   • Demo Booked             → pre-demo reminder at +22h
 *   • Talk to a human         → cancel everything pending (automation pauses)
 *
 * A fresh customer reply cancels the pending nudge/drip (the clock resets),
 * mirroring the doc's "any reply resets the clock".
 */
class LeadFlowAutomation
{
    public function __construct(private readonly WhatsAppMessageService $messages) {}

    /**
     * Queue the time-based follow-ups for the branch a lead just took. Called
     * right after a lead-flow keyword rule matches and its CRM actions apply.
     */
    public function scheduleForBranch(
        WhatsappPhoneNumber $phone,
        Conversation $conversation,
        Contact $contact,
        Chatbot $chatbot,
        ChatbotRule $rule
    ): void {
        if (! $chatbot->is_lead_flow) {
            return;
        }

        // A human handoff pauses the automation entirely.
        if ($rule->notify_agent) {
            $this->cancelAll($conversation);
            return;
        }

        $plan = $this->planForTag((string) $rule->set_tag);
        if ($plan === []) {
            return;
        }

        // Each branch owns its kinds — re-taking a branch refreshes its tasks.
        $kinds = array_map(fn ($t) => $t['kind'], $plan);
        $this->cancelPending($conversation, $kinds);

        $lead = $this->leadFor($phone->tenant_id, $contact);

        foreach ($plan as $t) {
            try {
                LeadFlowTask::withoutGlobalScopes()->create([
                    'tenant_id' => $phone->tenant_id,
                    'conversation_id' => $conversation->id,
                    'contact_id' => $contact->id,
                    'whatsapp_phone_number_id' => $phone->id,
                    'lead_id' => $lead?->id,
                    'kind' => $t['kind'],
                    'body' => $t['body'],
                    'buttons' => $t['buttons'] ?? null,
                    'due_at' => now()->addMinutes($t['in']),
                    'status' => LeadFlowTask::STATUS_PENDING,
                ]);
            } catch (\Throwable $e) {
                Log::warning('lead-flow: failed to schedule task: ' . $e->getMessage());
            }
        }
    }

    /** A customer reply resets the clock: drop the pending nudge/drip for this chat. */
    public function onCustomerReply(Conversation $conversation): void
    {
        $this->cancelPending($conversation, LeadFlowTask::REPLY_CANCELS);
    }

    /** Cancel every pending follow-up for a chat (used on human handoff). */
    public function cancelAll(Conversation $conversation): void
    {
        LeadFlowTask::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('status', LeadFlowTask::STATUS_PENDING)
            ->update(['status' => LeadFlowTask::STATUS_CANCELLED]);
    }

    private function cancelPending(Conversation $conversation, array $kinds): void
    {
        if ($kinds === []) {
            return;
        }

        LeadFlowTask::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('status', LeadFlowTask::STATUS_PENDING)
            ->whereIn('kind', $kinds)
            ->update(['status' => LeadFlowTask::STATUS_CANCELLED]);
    }

    /**
     * Send every follow-up that is due right now, across all tenants. Runs in
     * the scheduler. A chat assigned to a human, or no longer on the lead-flow
     * bot, is skipped (its pending tasks are cancelled).
     */
    public function sendDue(int $limit = 200): int
    {
        $sent = 0;

        $tasks = LeadFlowTask::withoutGlobalScopes()
            ->where('status', LeadFlowTask::STATUS_PENDING)
            ->where('due_at', '<=', now())
            ->with(['conversation', 'contact', 'phoneNumber'])
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        foreach ($tasks as $task) {
            try {
                $sent += $this->sendOne($task) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('lead-flow: send failed for task ' . $task->id . ': ' . $e->getMessage());
                $task->forceFill(['status' => LeadFlowTask::STATUS_FAILED])->saveQuietly();
            }
        }

        return $sent;
    }

    private function sendOne(LeadFlowTask $task): bool
    {
        $conversation = $task->conversation;
        $contact = $task->contact;
        $phone = $task->phoneNumber;

        if (! $conversation || ! $contact || ! $phone) {
            $task->forceFill(['status' => LeadFlowTask::STATUS_CANCELLED])->saveQuietly();
            return false;
        }

        // Once a human takes the chat, the bot steps back.
        if ($conversation->assigned_agent_id) {
            $task->forceFill(['status' => LeadFlowTask::STATUS_CANCELLED])->saveQuietly();
            return false;
        }

        $to = $contact->wa_id ?: ltrim((string) $contact->phone, '+');
        if (! $to) {
            $task->forceFill(['status' => LeadFlowTask::STATUS_CANCELLED])->saveQuietly();
            return false;
        }

        $buttons = is_array($task->buttons) ? array_filter($task->buttons) : [];

        // Outside WhatsApp's 24h service window a free-form text is rejected, so
        // a drip more than a day out uses an approved template when one is set.
        if ($task->template_name) {
            $result = $this->messages->sendTemplate($phone, $to, $task->template_name, 'en');
            $type = 'template';
        } elseif ($buttons !== []) {
            $result = $this->messages->sendButtons($phone, $to, $task->body, $buttons);
            $type = 'interactive';
        } else {
            $result = $this->messages->sendText($phone, $to, $task->body);
            $type = 'text';
        }

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $task->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phone->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => $type,
            'body' => $task->body,
            'external_message_id' => $this->messages->wamid($result),
            'status' => 'sent',
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($task->body, 100),
        ])->saveQuietly();

        $task->forceFill([
            'status' => LeadFlowTask::STATUS_SENT,
            'sent_at' => now(),
        ])->saveQuietly();

        return true;
    }

    private function leadFor(int $tenantId, Contact $contact): ?Lead
    {
        $number = $contact->phone ?: ($contact->wa_id ? '+' . ltrim((string) $contact->wa_id, '+') : null);
        if (! $number) {
            return null;
        }

        return Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone', $number)
            ->first();
    }

    /**
     * The drip plan for a funnel branch, keyed by the CRM tag the rule sets.
     * Each entry: in = minutes from now, kind, body (+optional buttons).
     *
     * @return array<int, array{kind:string, in:int, body:string, buttons?:array}>
     */
    private function planForTag(string $tag): array
    {
        return match ($tag) {
            'Qualified' => [
                ['kind' => 'nudge', 'in' => 24 * 60, 'body' => "Still want your free Google Business Profile score? 🔍 Just reply with your business name and city and our team will prepare it for you."],
                ['kind' => 'drip', 'in' => 3 * 24 * 60, 'body' => "Quick one 👋 businesses in your area are getting more calls just by fixing a few Google Business Profile gaps. Want us to run your free check? Reply with your business name to start.", 'buttons' => ['Check GBP score', 'Book free demo']],
            ],
            'Info Sent — Nurture' => [
                ['kind' => 'info_followup', 'in' => 2 * 24 * 60, 'body' => "Hi 🙂 did you get a chance to look at the overview and pricing I sent? Happy to answer any questions — or I can book you a quick 15-min demo.", 'buttons' => ['Book free demo', 'Talk to a human']],
            ],
            'Cold' => [
                ['kind' => 'cold_reengage', 'in' => 14 * 24 * 60, 'body' => "Hi again 👋 we recently helped a local business go from 3 photos and a broken call button to a steady stream of calls from Google. Want your free Business Profile check now?", 'buttons' => ['Check GBP score', 'See pricing']],
            ],
            'Demo Booked' => [
                ['kind' => 'demo_reminder', 'in' => 22 * 60, 'body' => "Reminder 📅 your free Eydia demo is coming up. We'll walk through your live Google Business Profile audit and exactly how to get more calls. See you there! If you need to reschedule, just reply here."],
            ],
            default => [],
        };
    }
}
