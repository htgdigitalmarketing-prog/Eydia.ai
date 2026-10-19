<?php

namespace App\Modules\Seo\Services;

use App\Modules\Chatbot\Services\AiReplyService;

/**
 * Small helper that runs the local-SEO AI prompts (keyword ideas, rank
 * estimate, competitor comparison) through the shared AiReplyService and parses
 * the JSON it returns. Degrades gracefully: when no AI provider is configured,
 * callers fall back to deterministic, honest guidance rather than fake numbers.
 */
class SeoAiService
{
    public function __construct(private AiReplyService $ai)
    {
    }

    public function configured(): bool
    {
        return ! empty($this->ai->configuredProviders());
    }

    /**
     * Ask the model for a JSON object and decode it. Returns null when AI is
     * unavailable or the reply was not valid JSON, so callers can fall back.
     */
    public function json(string $system, string $user, int $maxTokens = 400): ?array
    {
        $raw = $this->ai->classify($system, [['role' => 'user', 'content' => $user]]);
        if ($raw === null) {
            return null;
        }

        $clean = trim(preg_replace('/```json|```/', '', $raw));
        $parsed = json_decode($clean, true);

        return is_array($parsed) ? $parsed : null;
    }
}
