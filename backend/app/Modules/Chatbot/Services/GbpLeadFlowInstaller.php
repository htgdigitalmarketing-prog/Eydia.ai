<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\ChatbotRule;
use Illuminate\Support\Facades\DB;

/**
 * Installs the ready-made "GBP Lead Automation" WhatsApp funnel (Eydia
 * marketing/lead doc, section 3): a button-driven chatbot that greets a lead,
 * offers a free Google Business Profile check, books a demo / sends details,
 * tags each branch into the CRM and hands off to a human on request.
 *
 * Idempotent — re-running refreshes the same flow (matched by is_lead_flow).
 */
class GbpLeadFlowInstaller
{
    public const BOOKING_URL = 'https://calendly.com/eydia/demo';
    public const DETAILS_URL = 'https://eydia.in/pricing';

    public function install(int $tenantId): Chatbot
    {
        return DB::transaction(function () use ($tenantId) {
            $bot = Chatbot::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('is_lead_flow', true)->first()
                ?? new Chatbot(['tenant_id' => $tenantId]);

            $bot->forceFill([
                'tenant_id' => $tenantId,
                'name' => 'GBP Lead Automation',
                'is_active' => true,
                'is_lead_flow' => true,
                'whatsapp_phone_number_id' => null, // applies to every connected number
                'welcome_message' => "Hi 👋 I'm the Eydia assistant. I can check your Google Business Profile for free in about 30 seconds. What would you like to do?",
                'welcome_buttons' => ['Check GBP score', 'See pricing', 'Talk to a human'],
                'ai_enabled' => false,
                // The funnel runs its own, better-targeted drip (24h nudge → 3-day
                // drip → 2-day / 14-day re-engagement, via LeadFlowAutomation), so
                // the generic 6h/12h nudge is left off to avoid double-messaging.
                'followup_enabled' => false,
                'followup_message' => "Still want your free Google Business Profile score? Just reply with your business name and city 👇",
            ])->save();

            ChatbotRule::withoutGlobalScopes()->where('chatbot_id', $bot->id)->delete();

            foreach ($this->rules() as $i => $r) {
                ChatbotRule::withoutGlobalScopes()->create(array_merge([
                    'tenant_id' => $tenantId,
                    'chatbot_id' => $bot->id,
                    'match_type' => 'contains',
                    'response_type' => 'text',
                    'priority' => ($i + 1) * 10,
                    'is_active' => true,
                    'notify_agent' => false,
                ], $r));
            }

            return $bot->fresh();
        });
    }

    /** The funnel branches, in match-priority order. */
    private function rules(): array
    {
        return [
            [
                'keyword' => 'gbp score',
                'response_text' => "Awesome! 🔍 Share your Google Maps link — or just your business name + city — and our team will prepare your free Business Profile health check (rating, reviews, photos and the top issues costing you calls).\n\nBusinesses that fix these gaps often see more calls within 2–4 weeks. Want a free 15-min demo where we show you exactly how?",
                'buttons' => ['Book free demo', 'Just send details', 'Not now'],
                'set_stage' => 'CONTACTED',
                'set_tag' => 'Qualified',
            ],
            [
                'keyword' => 'pricing',
                'response_text' => "Here are our plans 👇\n\n• Starter — ₹999/mo: 1 GBP location, reviews + AI replies, photo posting\n• Growth — ₹2,999/mo: multiple locations, social posting, Leads CRM + WhatsApp\n• Agency — ₹9,999/mo: unlimited clients, ads reporting, white-label\n\nWant your free GBP score too?",
                'buttons' => ['Check GBP score', 'Book free demo'],
                'set_tag' => 'Pricing',
            ],
            [
                'keyword' => 'human',
                'response_text' => "Connecting you to our team — someone will reply here shortly. 👋 In the meantime, feel free to share your business name and city.",
                'set_stage' => 'CONTACTED',
                'set_tag' => 'Human Handoff',
                'notify_agent' => true,
            ],
            [
                'keyword' => 'book',
                'response_text' => "Perfect! 📅 Grab a slot that suits you here: " . self::BOOKING_URL . "\n\nYou'll get a reminder 24 hours and 1 hour before. See you then!",
                'set_stage' => 'APPOINTMENT',
                'set_tag' => 'Demo Booked',
            ],
            [
                'keyword' => 'details',
                'response_text' => "Done! 📄 Here's a quick overview + pricing: " . self::DETAILS_URL . "\n\nI'll check back in a couple of days to see if you have any questions. 🙂",
                'set_stage' => 'FOLLOW_UP',
                'set_tag' => 'Info Sent — Nurture',
            ],
            [
                'keyword' => 'not now',
                'response_text' => "No problem at all 👍 I'll check back later with something useful. Whenever you're ready for your free GBP score, just say hi.",
                'set_stage' => 'FOLLOW_UP',
                'set_tag' => 'Cold',
            ],
        ];
    }

    /** A plain-language description of the flow, for the install UI. */
    public static function steps(): array
    {
        return [
            ['trigger' => 'Lead messages / taps the ad', 'action' => 'Welcome + 3 buttons: Check GBP score · See pricing · Talk to a human'],
            ['trigger' => 'Check GBP score', 'action' => 'Asks for business name, offers a free demo → tags Qualified'],
            ['trigger' => 'See pricing', 'action' => 'Sends plan tiers, cross-sells the free GBP score → tags Pricing'],
            ['trigger' => 'Talk to a human', 'action' => 'Notifies your team + hands off → tags Human Handoff'],
            ['trigger' => 'Book free demo', 'action' => 'Sends the booking link → stage Appointment, tags Demo Booked'],
            ['trigger' => 'Just send details', 'action' => 'Sends overview + pricing, nurtures → tags Info Sent — Nurture'],
            ['trigger' => 'Not now', 'action' => 'Polite close, re-engages later → tags Cold'],
            ['trigger' => 'No reply after qualifying', 'action' => 'Auto nudge at 24h, then a long-term drip at 3 days'],
            ['trigger' => 'Details sent, still quiet', 'action' => 'Auto "did you get a chance to look?" follow-up at 2 days'],
            ['trigger' => 'Cold lead', 'action' => 'Auto re-engagement with a fresh hook at 14 days'],
            ['trigger' => 'Demo booked', 'action' => 'Auto pre-demo reminder so they show up'],
            ['trigger' => 'Any customer reply', 'action' => 'Resets the clock — pending nudges auto-cancel'],
        ];
    }
}
