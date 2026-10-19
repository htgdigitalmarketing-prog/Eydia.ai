<?php

namespace App\Modules\Gbp\Services;

/**
 * Local, no-AI sentiment classifier for Google reviews. A simple weighted
 * keyword match (negatives weigh a little heavier than positives) — cheap,
 * instant, and good enough to colour the inbox without spending AI credits.
 * Mirrors the ReviewFlow sentiment model.
 */
class SentimentService
{
    private const POSITIVE = [
        'wonderful', 'professional', 'best', 'great', 'recommend', 'excellent',
        'love', 'loved', 'good', 'amazing', 'awesome', 'friendly', 'helpful',
        'fantastic', 'perfect', 'happy', 'satisfied', 'clean', 'quick', 'polite',
        'nice', 'quality', 'affordable', 'value', 'thank', 'thanks',
    ];

    private const NEGATIVE = [
        'terrible', 'waited', 'waiting', 'frustrating', 'disappointed', 'expensive',
        'rude', 'dirty', 'worst', 'bad', 'poor', 'slow', 'horrible', 'awful',
        'never', 'cheat', 'scam', 'fraud', 'unprofessional', 'overpriced',
        'delay', 'delayed', 'refund', 'complaint', 'avoid', 'pathetic',
    ];

    /** @return 'positive'|'negative'|'neutral' */
    public function classify(?string $comment): string
    {
        $text = mb_strtolower(trim((string) $comment));
        if ($text === '') {
            return 'neutral';
        }

        $score = 0.0;
        foreach (self::POSITIVE as $w) {
            if (str_contains($text, $w)) {
                $score += 1.0;
            }
        }
        foreach (self::NEGATIVE as $w) {
            if (str_contains($text, $w)) {
                $score -= 1.2;
            }
        }

        if ($score > 0.5) {
            return 'positive';
        }
        if ($score < -0.5) {
            return 'negative';
        }

        return 'neutral';
    }
}
