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

    /**
     * The funnel branches, in match-priority order (lower priority = checked
     * first). The three welcome buttons drive the happy path; the regex branches
     * below cover the lead-management scenarios from the marketing doc (§4) that
     * a lead types in their own words — price objection, competitor, multi-
     * location, referral and a demo no-show / reschedule.
     */
    private function rules(): array
    {
        return [
            [
                'keyword' => 'gbp score',
                'response_text' => "Awesome! 🔍 Share your Google Maps link — or just your business name + city — and our team will prepare your free Business Profile health check (rating, reviews, photos and the top issues costing you calls).\n\nBusinesses that fix these gaps often see more calls within 2–4 weeks. Want a free 15-min demo where we show you exactly how?",
                'buttons' => ['Book free demo', 'Just send details', 'Not now'],
                'set_stage' => 'CONTACTED',
                'set_tag' => 'Qualified',
                'priority' => 10,
            ],
            [
                // Price objection — handled before the plain "pricing" branch so
                // "your pricing is too expensive" gets ROI framing, not a price list.
                'keyword' => 'expensive|too costly|costly|too much|over ?budget|can.?t afford|cheaper|discount',
                'match_type' => 'regex',
                'response_text' => "Totally fair to weigh it up 💡 Most of our clients find one or two extra calls a week more than covers it — a broken Google listing quietly loses you far more than the plan costs.\n\nWe also have a lighter Starter plan to begin with, no long lock-in. Want a quick demo so you can see the ROI on your own profile first?",
                'buttons' => ['Book free demo', 'See pricing'],
                'set_tag' => 'Objection — Price',
                'priority' => 15,
            ],
            [
                'keyword' => 'pricing',
                'response_text' => "Here are our plans 👇\n\n• Starter — ₹999/mo: 1 GBP location, reviews + AI replies, photo posting\n• Growth — ₹2,999/mo: multiple locations, social posting, Leads CRM + WhatsApp\n• Agency — ₹9,999/mo: unlimited clients, ads reporting, white-label\n\nWant your free GBP score too?",
                'buttons' => ['Check GBP score', 'Book free demo'],
                'set_tag' => 'Pricing',
                'priority' => 20,
            ],
            [
                // Referral — skip qualification, fast-track to a demo.
                'keyword' => 'referr|refer(ed|ral)|ref code|referral code|recommended by',
                'match_type' => 'regex',
                'response_text' => "Love that someone sent you our way! 🙌 You're on the fast track — let's skip the small talk and get you a live demo on your own Google profile. Pick a slot here: " . self::BOOKING_URL,
                'buttons' => ['Book free demo'],
                'set_stage' => 'CONTACTED',
                'set_tag' => 'Referral — Fast Track',
                'priority' => 22,
            ],
            [
                // Already using a competitor — position the human-team differentiator.
                'keyword' => 'already using|another tool|competitor|grexa|dhanda|mbg|switch from|currently use',
                'match_type' => 'regex',
                'response_text' => "Nice — you're clearly already taking Google seriously 👍 Quick question: what's the one thing your current tool *isn't* doing for you?\n\nMost tools are software-only and go quiet the moment something breaks (a suspension, a merged listing). We pair the AI with a team that's fixed these by hand for 10 years. Happy to run a free side-by-side audit so you can compare.",
                'buttons' => ['Check GBP score', 'Book free demo'],
                'set_tag' => 'Competitive',
                'priority' => 24,
            ],
            [
                // Multi-location — route to a human rep, flag as higher-value.
                'keyword' => 'multiple location|many location|[2-9][0-9]* ?location|locations|branches|franchise|outlets|chain of',
                'match_type' => 'regex',
                'response_text' => "Managing several locations? 🏢 That's right in our wheelhouse. I'm connecting you with a specialist who handles multi-location brands — they'll map out the whole rollout with you. One of our team will reply here shortly.",
                'set_stage' => 'CONTACTED',
                'set_tag' => 'Enterprise Lead',
                'notify_agent' => true,
                'priority' => 26,
            ],
            [
                'keyword' => 'human',
                'response_text' => "Connecting you to our team — someone will reply here shortly. 👋 In the meantime, feel free to share your business name and city.",
                'set_stage' => 'CONTACTED',
                'set_tag' => 'Human Handoff',
                'notify_agent' => true,
                'priority' => 30,
            ],
            [
                // Demo no-show / reschedule — checked before the "book" branch.
                'keyword' => 'reschedule|re-?book|missed (the|my)|could ?n.?t join|can.?t make|no.?show|another slot|different time',
                'match_type' => 'regex',
                'response_text' => "No worries at all — these things happen! 🙂 Let's grab you another slot that works better. Here's the link, pick any time today or later: " . self::BOOKING_URL,
                'buttons' => ['Book free demo'],
                'set_stage' => 'APPOINTMENT',
                'set_tag' => 'No-show — Rebook',
                'priority' => 35,
            ],
            [
                'keyword' => 'book',
                'response_text' => "Perfect! 📅 Grab a slot that suits you here: " . self::BOOKING_URL . "\n\nYou'll get a reminder 24 hours and 1 hour before. See you then!",
                'set_stage' => 'APPOINTMENT',
                'set_tag' => 'Demo Booked',
                'priority' => 40,
            ],
            [
                'keyword' => 'details',
                'response_text' => "Done! 📄 Here's a quick overview + pricing: " . self::DETAILS_URL . "\n\nI'll check back in a couple of days to see if you have any questions. 🙂",
                'set_stage' => 'FOLLOW_UP',
                'set_tag' => 'Info Sent — Nurture',
                'priority' => 50,
            ],
            [
                'keyword' => 'not now',
                'response_text' => "No problem at all 👍 I'll check back later with something useful. Whenever you're ready for your free GBP score, just say hi.",
                'set_stage' => 'FOLLOW_UP',
                'set_tag' => 'Cold',
                'priority' => 60,
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
            ['trigger' => '"Too expensive"', 'action' => 'ROI framing + Starter plan → tags Objection — Price'],
            ['trigger' => '"Already using X"', 'action' => 'Asks what\'s missing, manual-team pitch → tags Competitive'],
            ['trigger' => 'Multiple locations', 'action' => 'Routes to a specialist + alerts your team → tags Enterprise Lead'],
            ['trigger' => 'Referral / referred', 'action' => 'Skips qualifying, fast-tracks to a demo → tags Referral — Fast Track'],
            ['trigger' => 'Missed / reschedule', 'action' => 'Same-day rebook link → tags No-show — Rebook'],
            ['trigger' => 'No reply after qualifying', 'action' => 'Auto nudge at 24h, then a long-term drip at 3 days'],
            ['trigger' => 'Details sent, still quiet', 'action' => 'Day 2 nudge → Day 5 case study → Day 10 last offer'],
            ['trigger' => 'Cold lead', 'action' => 'Auto re-engagement with a fresh hook at 14 days'],
            ['trigger' => 'Demo booked', 'action' => 'Auto pre-demo reminder so they show up'],
            ['trigger' => 'Marked Won in CRM', 'action' => 'Auto review + referral ask at 30 days'],
            ['trigger' => 'Marked Lost in CRM', 'action' => 'Auto 90-day win-back check-in'],
            ['trigger' => 'Any customer reply', 'action' => 'Resets the clock — pending nudges auto-cancel'],
        ];
    }

    /**
     * The full lead playbook from the marketing doc (§4–§8): scenario matrix,
     * pipeline + follow-up cadence, remarketing triggers, how to present it in
     * marketing, sample ad creatives and the competitive landscape. Served (only
     * to the gated account) alongside the flow so the team has it in-app.
     */
    public static function playbook(): array
    {
        return [
            // §4 — Lead management scenarios: every branch and its response.
            'scenarios' => [
                ['scenario' => 'Hot lead', 'trigger' => 'Books a demo right after the score', 'response' => 'Confirmation + reminders; alert the sales rep', 'tag' => 'Demo Booked', 'auto' => true],
                ['scenario' => 'Warm lead', 'trigger' => 'Asks for pricing/details, no demo yet', 'response' => 'Send details + schedule a 2-day follow-up', 'tag' => 'Info Sent — Nurture', 'auto' => true],
                ['scenario' => 'Cold lead', 'trigger' => 'Says "not now" or goes silent', 'response' => 'Soft re-engagement in 14–30 days with a new hook', 'tag' => 'Cold', 'auto' => true],
                ['scenario' => 'No reply after Q1', 'trigger' => '24h silence', 'response' => 'One nudge; after 3 days → long-term drip list', 'tag' => 'Unresponsive', 'auto' => true],
                ['scenario' => 'Demo no-show', 'trigger' => 'Didn\'t join the booked call', 'response' => '"Sorry we missed you" + one-click rebook, same day', 'tag' => 'No-show — Rebook', 'auto' => true],
                ['scenario' => 'Price objection', 'trigger' => 'Says "too expensive"', 'response' => 'ROI framing (cost of lost calls) + smaller Starter plan', 'tag' => 'Objection — Price', 'auto' => true],
                ['scenario' => 'Using a competitor', 'trigger' => 'Mentions an existing tool', 'response' => 'Ask what\'s missing, position the human team, offer side-by-side audit', 'tag' => 'Competitive', 'auto' => true],
                ['scenario' => 'Referral', 'trigger' => 'Comes via a referral link/code', 'response' => 'Skip qualification, fast-track to demo, flag the referral source', 'tag' => 'Referral — Fast Track', 'auto' => true],
                ['scenario' => 'Multi-location', 'trigger' => 'Mentions 2+ locations', 'response' => 'Route to a sales rep directly; flag higher-value', 'tag' => 'Enterprise Lead', 'auto' => true],
                ['scenario' => 'Wrong fit', 'trigger' => 'No Google listing / outside service area', 'response' => 'Polite disqualify, add to a separate "future prospect" list', 'tag' => 'Disqualified', 'auto' => false],
                ['scenario' => 'Spam / bot', 'trigger' => 'Gibberish or irrelevant input', 'response' => 'No CRM entry; silently ignored or generic fallback reply', 'tag' => '—', 'auto' => true],
                ['scenario' => 'Closed-won', 'trigger' => 'Signs up', 'response' => 'Auto welcome-to-onboarding; review/referral ask scheduled for +30 days', 'tag' => 'Customer', 'auto' => true],
                ['scenario' => 'Closed-lost', 'trigger' => 'Explicitly declines', 'response' => 'Tag and stop active follow-up; keep in long-term (90-day) re-engagement', 'tag' => 'Lost', 'auto' => true],
            ],
            // §5 — CRM pipeline + follow-up cadence.
            'pipeline' => ['New Lead', 'Qualified (score sent)', 'Demo Booked', 'Demo Done', 'Proposal Sent', 'Customer (won) / Lost'],
            'cadence' => [
                ['stage' => 'Qualified, no demo booked', 'timing' => 'Day 0 details sent → Day 2 nudge → Day 5 case study → Day 10 last offer', 'channel' => 'WhatsApp'],
                ['stage' => 'Demo booked', 'timing' => 'Confirmation instantly → 24h reminder → 1h reminder', 'channel' => 'WhatsApp'],
                ['stage' => 'Demo no-show', 'timing' => 'Same-day rebook message → next-day call from rep', 'channel' => 'WhatsApp + call'],
                ['stage' => 'Proposal sent, no response', 'timing' => 'Day 2 check-in → Day 5 incentive/discount nudge → Day 10 "closing soon"', 'channel' => 'WhatsApp'],
                ['stage' => 'Cold / not-now', 'timing' => 'Day 14–30 soft re-engagement with a new angle', 'channel' => 'WhatsApp'],
                ['stage' => 'Lost', 'timing' => 'Day 90 re-engagement check-in', 'channel' => 'WhatsApp'],
                ['stage' => 'Customer', 'timing' => 'Day 30 review ask + referral ask → monthly performance summary', 'channel' => 'WhatsApp'],
            ],
            'remarketing' => [
                'Visited the landing page but didn\'t message → retarget with the GBP-score hook again.',
                'Opened WhatsApp but didn\'t finish qualifying → retarget with a "your free score is waiting" ad.',
                'Demo booked but no-show → retarget with a testimonial/case-study ad + the rebook link.',
                'Customers at 30/60/90 days → build lookalike audiences from their profile for new targeting.',
            ],
            // §6 — How to present it in marketing.
            'marketing' => [
                'landing' => [
                    'Headline names the pain, not the product: "Is Your Business Invisible on Google Maps?"',
                    'Instant free-audit CTA (WhatsApp button) above the fold — no long form.',
                    '"10 years, not just AI" trust section — before/after examples of fixed profiles.',
                    '3-step visual: Scan → Fix → Grow.',
                    'Short testimonials / case studies with real numbers (calls up X%, reviews up Y).',
                    'Pricing tiers with a clear Starter plan.',
                    'Final CTA repeating the free WhatsApp audit.',
                ],
                'pitch' => [
                    'Open with their own live GBP data on screen (not a generic deck) — immediately personal.',
                    'Name 2–3 specific problems found, in plain language, with the business impact.',
                    'Show the AI in action (auto-reply to a review, auto-generated post).',
                    'Introduce the manual team as the safety net — a real person who\'s done this 10 years.',
                    'Show the WhatsApp automation itself as a feature ("this is the same system working for your customers").',
                    'Close with a simple, time-boxed offer (first month discounted, free onboarding).',
                ],
                'trust' => 'Repeat everywhere: years of experience, real before/after profile data, human support promise, no long-term lock-in on the Starter plan.',
            ],
            // §7 — Sample ad creatives (all Click-to-WhatsApp).
            'ads' => [
                'meta' => [
                    ['angle' => 'Fear / loss', 'headline' => 'Your Customers Are Googling You Right Now', 'text' => 'Most local searches end in a call or a visit — but a broken or outdated Google profile sends that customer to a competitor instead. Get a free 30-second check on WhatsApp.'],
                    ['angle' => 'Curiosity', 'headline' => "What's Your Google Business Score?", 'text' => "We'll scan your Google Business Profile free and tell you exactly what's costing you customers. Takes 30 seconds on WhatsApp."],
                    ['angle' => 'Trust / authority', 'headline' => '10 Years Fixing Google Profiles — Now With AI', 'text' => "We've manually fixed thousands of suspended and broken Google listings. Now our AI keeps yours optimised 24/7. Get your free audit."],
                    ['angle' => 'Social proof', 'headline' => 'See Why Local Businesses Are Switching', 'text' => '[Business name] saw more calls within weeks of fixing their Google profile with us. Want the same free check for your business?'],
                ],
                'google' => ['Free Google Business Audit', 'AI + 10 Yrs Human Experience', 'Fix Your GBP in Minutes'],
                'notes' => [
                    'Lead creative tests with the "fear/loss" and "curiosity" angles — they beat generic feature ads for local-service audiences.',
                    'Always pair the ad with the free-audit hook so the WhatsApp automation has something concrete to deliver immediately.',
                    'Rotate in a new real before/after result every 2–4 weeks to keep creative fatigue down.',
                ],
            ],
            // §8 — Competitive landscape.
            'competitive' => [
                'columns' => ['', 'Eydia.ai', 'Grexa.ai', 'Dhanda.ai', 'MBG Card'],
                'rows' => [
                    ['Core offer', 'GBP management + WhatsApp AI chatbot, backed by a human team', '3 AI "agents": GBP, WhatsApp Chat, WhatsApp Marketing', 'GBP growth tool only (audit, SEO, reviews, posts) — no WhatsApp', 'WhatsApp automation + GBP + content/ads/CRM, widest feature set'],
                    ['Key differentiator', '10 years of manual GBP fix/optimisation experience behind the AI', '"AI Marketing Team" — outcome-led branding, agent framing', 'Simplicity, no marketing skill needed', 'Breadth — everything in one place'],
                    ['Entry funnel', 'Free WhatsApp GBP audit → demo', 'Free WhatsApp AI agent trial → 7-day onboarding', 'Free Google Score Audit in-app', 'Free strategy call (sales-gated, no instant self-serve)'],
                    ['Known weakness', 'Early-stage, no public track record yet', 'AI-only framing, no human-support story', 'At least one visible support/delivery complaint', 'No self-serve pricing, slower funnel, weaker proof section'],
                ],
                'takeaways' => [
                    'All three competitors validate the same "free Google/WhatsApp audit → paid fix" funnel — keep that as the core hook.',
                    'None lead with a human-expertise story the way Eydia can (10 years manual GBP fixing) — make it the headline differentiator everywhere.',
                    'MBG Card is the closest feature-for-feature rival; beat it with instant self-serve value and real before/after numbers.',
                    'Grexa has the strongest trust/social-proof layer — that\'s the benchmark to match once Eydia has real usage numbers.',
                ],
            ],
        ];
    }
}
