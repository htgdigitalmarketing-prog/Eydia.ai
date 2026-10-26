<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Modules\Chatbot\Services\AiReplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super Admin → Settings → AI Provider. Lets the admin pick ONE active provider
 * (Gemini / Anthropic / OpenAI), set its API key + model, activate it, and test
 * it live. Keys are write-only from the UI (never sent back in full) and stored
 * encrypted. When no active provider is set, the app uses the .env keys.
 */
class AiSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->ok(['ai' => $this->toArray(AiSetting::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'active_provider' => ['sometimes', 'nullable', Rule::in(AiSetting::PROVIDERS)],
            'gemini_model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'anthropic_model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'openai_model' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Keys are optional — only overwrite when a non-empty, non-masked value is sent.
            'gemini_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'anthropic_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'openai_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $settings = AiSetting::current();

        foreach (['is_active', 'active_provider', 'gemini_model', 'anthropic_model', 'openai_model'] as $field) {
            if (array_key_exists($field, $data)) {
                $settings->{$field} = $data[$field];
            }
        }
        foreach (['gemini_api_key', 'anthropic_api_key', 'openai_api_key'] as $field) {
            if (array_key_exists($field, $data) && $this->isNewKey($data[$field])) {
                $settings->{$field} = $data[$field];
            }
        }

        // Turning it on requires the chosen provider to actually have a key.
        if ($settings->is_active && ! $settings->activeProvider()) {
            return $this->fail('Select a provider that has an API key before activating.', ['active_provider' => ['no key']], 422);
        }

        $settings->save();

        return $this->ok(['ai' => $this->toArray($settings->fresh()), 'message' => 'AI provider settings saved.']);
    }

    /** Live-test a provider's key + model. */
    public function test(Request $request, AiReplyService $ai): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(AiSetting::PROVIDERS)],
        ]);

        return $this->ok(['result' => $ai->testProvider($data['provider'])]);
    }

    /** Whether a submitted key is a real new value (not empty, not the masked dots). */
    private function isNewKey(?string $value): bool
    {
        $v = trim((string) $value);

        return $v !== '' && ! preg_match('/^[•*]+$/u', $v);
    }

    /** UI shape — keys are never returned, only whether one is set + its last 4. */
    private function toArray(AiSetting $s): array
    {
        $providers = [];
        foreach (AiSetting::PROVIDERS as $p) {
            $dbKey = $s->{$p . '_api_key'} ?: null;
            $effective = $s->keyFor($p);
            $providers[$p] = [
                'has_key' => (bool) $effective,
                'key_source' => $dbKey ? 'saved' : ($effective ? 'env' : 'none'),
                'key_last4' => $effective ? substr($effective, -4) : null,
                'model' => $s->modelFor($p),
                'model_default' => AiSetting::DEFAULT_MODELS[$p] ?? '',
            ];
        }

        return [
            'is_active' => (bool) $s->is_active,
            'active_provider' => $s->active_provider,
            'effective_provider' => $s->activeProvider(),
            'providers' => $providers,
        ];
    }
}
