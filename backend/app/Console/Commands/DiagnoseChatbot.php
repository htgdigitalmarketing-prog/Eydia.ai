<?php

namespace App\Console\Commands;

use App\Models\Chatbot;
use App\Modules\Chatbot\Services\AiReplyService;
use Illuminate\Console\Command;

/**
 * Tells you, on the server, exactly why the AI chatbot is or isn't replying:
 * which AI providers have a key, whether a live test call succeeds, and whether
 * each tenant's chatbot is active with AI or a fallback set.
 *
 *   php artisan chatbot:diagnose
 */
class DiagnoseChatbot extends Command
{
    protected $signature = 'chatbot:diagnose';
    protected $description = 'Diagnose why the AI chatbot is not replying (keys, providers, chatbots)';

    public function handle(AiReplyService $ai): int
    {
        $this->line('');
        $this->info('AI providers configured (in order they are tried):');
        $providers = $ai->configuredProviders();
        if ($providers === []) {
            $this->error('  ✗ NONE. Set GEMINI_API_KEY (AIza…) or ANTHROPIC_API_KEY in .env, then: php artisan config:clear');
        } else {
            foreach ($providers as $p) {
                $this->line('  • ' . $p);
            }
        }

        $this->line('');
        $this->info('Live AI test call:');
        $reply = $ai->classify('You are a test. Reply with exactly: OK', [
            ['role' => 'user', 'content' => 'Say OK'],
        ]);
        if ($reply) {
            $this->line('  ✓ AI responded: ' . trim($reply));
        } else {
            $this->error('  ✗ AI returned nothing. The key is likely invalid/expired, the model name is wrong,');
            $this->error('    or the provider is unreachable. Check storage/logs/laravel.log for "AI reply".');
        }

        $this->line('');
        $this->info('Active chatbots:');
        $bots = Chatbot::withoutGlobalScopes()->where('is_active', true)->get();
        if ($bots->isEmpty()) {
            $this->warn('  No active chatbots. Turn one on in the Chatbot page.');
        }
        foreach ($bots as $bot) {
            $rules = \App\Models\ChatbotRule::withoutGlobalScopes()->where('chatbot_id', $bot->id)->where('is_active', true)->count();
            $this->line(sprintf(
                '  • tenant %s: ai_enabled=%s, rules=%d, fallback=%s',
                $bot->tenant_id,
                $bot->ai_enabled ? 'yes' : 'no',
                $rules,
                $bot->fallback_message ? 'set' : 'EMPTY',
            ));
        }

        $this->line('');
        $this->comment('Rule of thumb: if the live test fails, fix the API key. If it passes but customers get no reply, make sure the chatbot is active, AI is enabled (or rules match), and a fallback message is set.');

        return self::SUCCESS;
    }
}
