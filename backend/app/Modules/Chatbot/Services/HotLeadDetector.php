<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hot-lead detection. The AI that is chatting with the customer also decides who
 * is a hot lead:
 *
 *  - It reads the recent conversation (customer + bot lines) and the business info.
 *  - If the customer shows buying interest it answers hot=true with a PRIORITY SCORE
 *    (1-100) and a short reason. Higher score = talk to this person first.
 *  - The contact gets the "Hot" tag in contacts.tag_list; score/reason are kept in
 *    contacts.meta.hot (no extra database columns).
 *  - Inbox and Hot List show the highest score on top.
 *
 * If the AI is unavailable (no key / request failed) a keyword check is the fallback.
 * HOT_LEAD_AI=false skips the AI and uses keywords only.
 */
class HotLeadDetector
{
    /** [reason label => regex alternatives]. First match wins. */
    private const INTEREST = [
        'Asked about pricing' => 'price|prices|pricing|cost|costing|rate|rates|charges?|fees?|quote|quotation|how much|kitna|kitne|kimat|keemat|daam|dam kya|कीमत|कितना|कितने|दाम|रेट|भाव',
        'Wants a demo / call / meeting' => 'demo|free trial|trial|meeting|appointment|site visit|visit|schedule|call me|call back|callback|give me a call|call kar(?:o|na|ein)?|phone kar(?:o|na)?|कॉल',
        'Wants to buy / order / book' => 'buy|purchase|order|book|booking|book kar(?:o|na)?|lena hai|leni hai|len[ae]|kharid(?:na|ni)?|khareed(?:na|ni)?|खरीदना|लेना है|बुक',
        'Expressed interest' => 'interested|interest|i want|we want|i need|we need|looking for|require|requirement|chahiye|chahie|chahta|chahti|zarurat|ज़रूरत|जरूरत|चाहिए|रुचि|इंटरेस्ट',
        'Asked for details / brochure' => 'details|detail|more info|information|brochure|catalog|catalogue|portfolio|samples?|plans?|packages?|offers?|discount|jankari|batao|bataiye|bataye|बताइए|बताओ|जानकारी',
        'Ready to proceed / pay' => 'payment|pay now|invoice|proceed|go ahead|confirm|confirmed|let\'?s (?:do|start|proceed|go)|start now|sign ?up|register|onboard',
        'Came from a lead ad / filled the form' => 'filled (?:in|out) (?:your|the|my) form|i filled (?:in|out)|form bhara|form fill (?:kiya|kar diya)|फॉर्म भरा',
    ];

    /** Priority score the keyword fallback gives for each reason. */
    private const KEYWORD_SCORE = [
        'Asked about pricing' => 60,
        'Wants a demo / call / meeting' => 75,
        'Wants to buy / order / book' => 85,
        'Expressed interest' => 55,
        'Asked for details / brochure' => 45,
        'Ready to proceed / pay' => 90,
        'Came from a lead ad / filled the form' => 65,
    ];

    /** Explicit no-interest signals — checked first, they win over everything. */
    private const NEGATIVE = 'not interested|no interest|not looking|don\'?t (?:want|need|call|message)|do not (?:want|need|call|message)|stop|unsubscribe|remove me|no thanks|no thank you|not now|nahi chahiye|nhi chahiye|interested nahi|interested nhi|mat (?:karo|bhejo|bhejna)|band karo|नहीं चाहिए|रुचि नहीं|मत भेजो|मत करो|बंद करो';

    private const AI_SYSTEM = <<<'TXT'
You are the sales assistant of a business chatting with customers on WhatsApp. Besides answering, you decide which customers are HOT LEADS so the team can call them first.

You get the business information, the recent conversation (Customer / Business lines) and the customer's latest message. Judge the latest message IN CONTEXT of the conversation.

HOT (true) when the customer shows real buying interest, for example:
- asks about price, plans, packages, availability, timelines or how the service works for them
- wants a demo, call, meeting, site visit or quotation
- wants to order, book, buy or sign up, or asks how to pay
- shares their requirement or says they are interested / "haan", "yes", "ok send" in reply to the business offering something
- asks for details, brochure, samples or portfolio in a way that shows they may buy
- opens with "I filled in your form / I filled out your form and would like to know more" (or similar) — this means they came from a lead ad and already showed intent, so treat it as hot unless the rest of the chat clearly shows otherwise

NOT hot (false):
- greetings, thanks, one-word acknowledgements with no offer to accept, spam, jokes
- support problems or complaints, job seekers, wrong number
- anyone who says they are not interested / do not want it / stop messaging

PRIORITY SCORE (1-100, only meaningful when hot is true) - who should be contacted first:
- 90-100: ready to buy now (wants to pay/book/order, asks to start today, gives a deadline)
- 70-89: strong interest (asks for a demo/call/meeting/visit/quotation, shares a clear requirement)
- 40-69: interested but early (asks about price, plans or details)
- 1-39: weak or vague interest
Score higher for urgency, a clear budget/requirement, and customers who keep asking follow-up questions.

The customer may write in English, Hindi or Hinglish.
Reply with ONLY a JSON object and nothing else:
{"hot": true or false, "score": <1-100>, "reason": "<max 12 words, why>"}
TXT;

    public function __construct(private readonly AiReplyService $ai) {}

    /**
     * @return array{hot: bool, score: int, reason: string}|null  null = skipped / nothing decided
     */
    public function evaluate(Contact $contact, Conversation $conversation, string $type, ?string $body): ?array
    {
        if (! in_array($type, ['text', 'button', 'interactive'], true)) {
            return null;
        }

        $body = trim((string) $body);
        if ($body === '' || $contact->is_blocked || $contact->opted_out) {
            return null;
        }

        // Already at top priority — nothing more to gain from another AI call.
        if ($contact->is_hot && $contact->hot_score >= 90) {
            return null;
        }

        $verdict = null;

        // 1) AI decides (main path).
        if (config('services.hot_lead.ai', true)) {
            $verdict = $this->byAi($conversation, $body);
        }

        // 2) Fallback only when the AI could not answer (no key / request failed).
        if ($verdict === null) {
            $verdict = $this->byKeywords($body);
            if ($verdict !== null) {
                Log::info('hot-lead: AI unavailable, used keyword fallback', ['contact' => $contact->id]);
            }
        }

        if ($verdict && $verdict['hot']) {
            $this->markHot($contact, $verdict['reason'], $verdict['score']);
        }

        return $verdict;
    }

    /**
     * Evaluate a conversation using its latest customer message (used by the
     * `hotlist:scan` command to catch chats that happened before this feature).
     *
     * @return array{hot: bool, score: int, reason: string}|null
     */
    public function evaluateConversation(Conversation $conversation): ?array
    {
        $last = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->whereIn('type', ['text', 'button', 'interactive'])
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->first();

        $contact = Contact::withoutGlobalScopes()->find($conversation->contact_id);

        if (! $last || ! $contact) {
            return null;
        }

        return $this->evaluate($contact, $conversation, $last->type, $last->body);
    }

    /**
     * @return array{hot: bool, score: int, reason: string}|null  null = inconclusive
     */
    private function byKeywords(string $body): ?array
    {
        if (preg_match($this->wrap(self::NEGATIVE), $body) === 1) {
            return ['hot' => false, 'score' => 0, 'reason' => 'Not interested'];
        }

        foreach (self::INTEREST as $label => $alternatives) {
            if (preg_match($this->wrap($alternatives), $body) === 1) {
                return ['hot' => true, 'score' => self::KEYWORD_SCORE[$label] ?? 50, 'reason' => $label . ': “' . Str::limit($body, 90) . '”'];
            }
        }

        return null;
    }

    /**
     * @return array{hot: bool, score: int, reason: string}|null  null = AI unavailable / unusable answer
     */
    private function byAi(Conversation $conversation, string $body): ?array
    {
        $recent = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('type', 'text')
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->reverse();

        $transcript = $recent
            ->map(fn (Message $m) => ($m->direction === Message::DIRECTION_OUTBOUND ? 'Business' : 'Customer')
                . ': ' . Str::limit(trim((string) $m->body), 300))
            ->implode("\n");

        $business = trim((string) Chatbot::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('is_active', true)
            ->whereNotNull('ai_instructions')
            ->value('ai_instructions'));

        $prompt = ($business !== '' ? "Business information:\n" . Str::limit($business, 1500) . "\n\n" : '')
            . "Conversation so far:\n{$transcript}\n\nLatest customer message: {$body}";

        $raw = $this->ai->classify(self::AI_SYSTEM, [['role' => 'user', 'content' => $prompt]]);

        if (! $raw || ! preg_match('/\{.*\}/s', $raw, $m)) {
            return null;
        }

        $json = json_decode($m[0], true);
        if (! is_array($json) || ! array_key_exists('hot', $json)) {
            return null;
        }

        $hot = $json['hot'] === true || $json['hot'] === 'true';
        $reason = trim((string) ($json['reason'] ?? '')) ?: 'Shows buying interest';
        $score = $hot ? max(1, min(100, (int) ($json['score'] ?? 50))) : 0;

        Log::info('hot-lead: AI verdict', ['conversation' => $conversation->id, 'hot' => $hot, 'score' => $score, 'reason' => $reason]);

        return ['hot' => $hot, 'score' => $score, 'reason' => 'AI: ' . Str::limit($reason, 200)];
    }

    /**
     * Add the "Hot" tag to contacts.tag_list and keep the AI's score/reason in meta.hot.
     * An already-hot contact keeps its strongest score; a stronger new signal replaces
     * the reason too.
     */
    private function markHot(Contact $contact, string $reason, int $score): void
    {
        $others = array_values(array_filter(
            $contact->tagNames(),
            fn ($t) => mb_strtolower($t) !== mb_strtolower(Contact::HOT_TAG)
        ));

        $meta = is_array($contact->meta) ? $contact->meta : [];
        $prev = is_array($meta['hot'] ?? null) ? $meta['hot'] : [];
        $prevScore = (int) ($prev['score'] ?? 0);

        $stronger = $score >= $prevScore;

        $meta['hot'] = [
            'score' => max($score, $prevScore),
            'reason' => $stronger ? Str::limit($reason, 250) : ($prev['reason'] ?? Str::limit($reason, 250)),
            'at' => $prev['at'] ?? now()->toIso8601String(),   // first time flagged
            'last_at' => now()->toIso8601String(),
        ];

        $contact->forceFill([
            'tag_list' => mb_substr(implode(', ', array_merge([Contact::HOT_TAG], $others)), 0, 500),
            'meta' => $meta,
        ])->save();

        Log::info('hot-lead: contact tagged Hot', ['contact' => $contact->id, 'score' => $meta['hot']['score'], 'reason' => $reason]);
    }

    /** Whole-word, case-insensitive, unicode-safe (works for Hindi too). */
    private function wrap(string $alternatives): string
    {
        return '/(?<![\p{L}\p{N}])(?:' . $alternatives . ')(?![\p{L}\p{N}])/iu';
    }
}