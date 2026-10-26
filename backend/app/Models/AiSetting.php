<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform AI provider settings. Singleton — one row, managed by the Super Admin.
 * Not tenant-scoped. When {@see is_active} is on and the chosen provider has a
 * key, the app uses ONLY that provider + model; otherwise it falls back to the
 * .env configuration. API keys are encrypted at rest.
 */
class AiSetting extends Model
{
    public const PROVIDERS = ['gemini', 'anthropic', 'openai'];

    /** Sensible default models per provider, used when none is set. */
    public const DEFAULT_MODELS = [
        'gemini' => 'gemini-3.6-flash',
        'anthropic' => 'claude-opus-5',
        'openai' => 'gpt-4o-mini',
    ];

    protected $fillable = [
        'is_active', 'active_provider',
        'gemini_api_key', 'gemini_model',
        'anthropic_api_key', 'anthropic_model',
        'openai_api_key', 'openai_model', 'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        // Encrypted at rest; APP_KEY must be stable or keys become unreadable.
        'gemini_api_key' => 'encrypted',
        'anthropic_api_key' => 'encrypted',
        'openai_api_key' => 'encrypted',
        'meta' => 'array',
    ];

    /** The single settings row, created on first access. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    /** The effective API key for a provider: DB override, else the .env value. */
    public function keyFor(string $provider): ?string
    {
        $db = $this->{$provider . '_api_key'} ?: null;

        return $db ?: match ($provider) {
            'gemini' => config('services.gemini.key'),
            'anthropic' => config('services.anthropic.key'),
            'openai' => config('services.openai.key'),
            default => null,
        };
    }

    /** The effective model for a provider: DB override, else .env, else default. */
    public function modelFor(string $provider): string
    {
        $db = $this->{$provider . '_model'} ?: null;
        $env = match ($provider) {
            'gemini' => config('services.gemini.model'),
            'anthropic' => config('services.anthropic.model'),
            'openai' => config('services.openai.model'),
            default => null,
        };

        return $db ?: ($env ?: (self::DEFAULT_MODELS[$provider] ?? ''));
    }

    /** The forced single provider when active, else null (auto-detect from keys). */
    public function activeProvider(): ?string
    {
        if (! $this->is_active || ! in_array($this->active_provider, self::PROVIDERS, true)) {
            return null;
        }

        return $this->keyFor($this->active_provider) ? $this->active_provider : null;
    }
}
