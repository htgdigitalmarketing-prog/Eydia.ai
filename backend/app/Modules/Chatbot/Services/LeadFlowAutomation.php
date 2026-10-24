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

        $lead = $this->leadFor($phone->tenant_id, $contact);
        $this->queue($plan, $phone->tenant_id, $conversation->id, $contact->id, $phone->id, $lead?->id);
    }

    /**
     * Schedule the post-sale cadence when a rep moves a lead to Won or Lost in
     * the CRM (marketing doc §5): a Won lead gets a review + referral ask at 30
     * days, a Lost lead a 90-day win-back. Only fires for a tenant that actually
     * runs the lead-flow funnel (keeps it to the gated account) and whose lead
     * has a WhatsApp conversation to reach.
     */
    public function scheduleForLeadStage(Lead $lead): void
    {
        $plan = $this->planForStage((string) $lead->stage);
        if ($plan === []) {
            return;
        }

        $hasFunnel = Chatbot::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)->where('is_lead_flow', true)->exists();
        if (! $hasFunnel || ! $lead->phone) {
            return;
        }

        $contact = Contact::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->where(function ($q) use ($lead) {
                $q->where('phone', $lead->phone)->orWhere('wa_id', ltrim($lead->phone, '+'));
            })
            ->first();
        if (! $contact) {
            return;
        }

        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->where('contact_id', $contact->id)
            ->orderByDesc('last_message_at')
            ->first();
        if (! $conversation) {
            return;
        }

        $phoneId = $conversation->whatsapp_phone_number_id
            ?? WhatsappPhoneNumber::withoutGlobalScopes()->where('tenant_id', $lead->tenant_id)->value('id');

        // Tag the lead for the CRM, then queue the cadence.
        $meta = $lead->meta ?? [];
        $meta['tag'] = $lead->stage === 'CONVERTED' ? 'Customer' : 'Lost';
        $meta['flow'] = 'gbp_lead_automation';
        $lead->forceFill(['meta' => $meta])->saveQuietly();

        $this->queue($plan, $lead->tenant_id, $conversation->id, $contact->id, $phoneId, $lead->id);
    }

    /** Create the drip tasks for a plan, cancelling any pending of the same kinds first. */
    private function queue(array $plan, int $tenantId, int $conversationId, int $contactId, ?int $phoneId, ?int $leadId): void
    {
        if ($plan === []) {
            return;
        }

        // Each branch/stage owns its kinds — re-taking it refreshes those tasks.
        $kinds = array_map(fn ($t) => $t['kind'], $plan);
        LeadFlowTask::withoutGlobalScopes()
            ->where('conversation_id', $conversationId)
            ->where('status', LeadFlowTask::STATUS_PENDING)
            ->whereIn('kind', $kinds)
            ->update(['status' => LeadFlowTask::STATUS_CANCELLED]);

        foreach ($plan as $t) {
            try {
                LeadFlowTask::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'contact_id' => $contactId,
                    'whatsapp_phone_number_id' => $phoneId,
                    'lead_id' => $leadId,
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
            // Warm lead (details/pricing sent): Day 2 nudge → Day 5 case study → Day 10 last offer.
            'Info Sent — Nurture' => [
                ['kind' => 'info_followup', 'in' => 2 * 24 * 60, 'body' => "Hi 🙂 did you get a chance to look at the overview and pricing I sent? Happy to answer any questions — or I can book you a quick 15-min demo.", 'buttons' => ['Book free demo', 'Talk to a human']],
                ['kind' => 'case_study', 'in' => 5 * 24 * 60, 'body' => "Quick proof 📈 a business near you went from barely showing up on Maps to a steady stream of calls after we fixed their profile. Want us to run your free check and show what's possible for you?", 'buttons' => ['Check GBP score', 'Book free demo']],
                ['kind' => 'last_offer', 'in' => 10 * 24 * 60, 'body' => "Last nudge from me 🙌 we're offering a discounted first month + free onboarding this week. Want me to set you up, or book a quick demo first?", 'buttons' => ['Book free demo', 'See pricing']],
            ],
            'Cold' => [
                ['kind' => 'cold_reengage', 'in' => 14 * 24 * 60, 'body' => "Hi again 👋 we recently helped a local business go from 3 photos and a broken call button to a steady stream of calls from Google. Want your free Business Profile check now?", 'buttons' => ['Check GBP score', 'See pricing']],
            ],
            'Demo Booked' => [
                ['kind' => 'demo_reminder', 'in' => 22 * 60, 'body' => "Reminder 📅 your free Eydia demo is coming up. We'll walk through your live Google Business Profile audit and exactly how to get more calls. See you there! If you need to reschedule, just reply here."],
            ],
            'No-show — Rebook' => [
                ['kind' => 'rebook_reminder', 'in' => 24 * 60, 'body' => "Still keen to see your free Google Business Profile audit? 🙂 Want me to grab you another slot — any time works.", 'buttons' => ['Book free demo', 'Talk to a human']],
            ],
            'Objection — Price' => [
                ['kind' => 'objection_followup', 'in' => 24 * 60, 'body' => "Just so it's clear 💡 one missed call a week from a broken listing usually costs more than the whole plan. We can start you on the lighter Starter plan with no lock-in. Want a quick demo on your own profile first?", 'buttons' => ['Book free demo', 'See pricing']],
            ],
            'Competitive' => [
                ['kind' => 'competitor_followup', 'in' => 2 * 24 * 60, 'body' => "Still happy to run that free side-by-side audit whenever you like 👍 the big difference: when something breaks on Google, you get a human team that's fixed it for 10 years — not just software. Want us to check your profile?", 'buttons' => ['Check GBP score', 'Book free demo']],
            ],
            'Referral — Fast Track' => [
                ['kind' => 'referral_reminder', 'in' => 24 * 60, 'body' => "Your fast-track demo slot is still open 🙌 pick any time that suits you and we'll walk through your live Google profile together.", 'buttons' => ['Book free demo']],
            ],
            default => [],
        };
    }

    /**
     * The post-sale cadence for a CRM pipeline stage (marketing doc §5).
     * CONVERTED ≈ Customer (won), LOST ≈ Lost.
     *
     * @return array<int, array{kind:string, in:int, body:string, buttons?:array}>
     */
    private function planForStage(string $stage): array
    {
        return match ($stage) {
            'CONVERTED' => [
                ['kind' => 'review_ask', 'in' => 30 * 24 * 60, 'body' => "You've been with us a month now 🎉 if Eydia's helped bring in more calls, a quick Google review would mean the world — and if you know another business owner who'd benefit, we'd love an intro. Thank you! 🙏"],
            ],
            'LOST' => [
                ['kind' => 'winback', 'in' => 90 * 24 * 60, 'body' => "Hi 👋 it's been a while! Google keeps changing how local businesses get found and we've shipped a lot since we last spoke. Want a fresh free check on your Business Profile — no strings?", 'buttons' => ['Check GBP score', 'See pricing']],
            ],
            default => [],
        };
    }
}
