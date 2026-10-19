<?php

namespace App\Modules\Chatbot\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatbotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'welcome_message' => $this->welcome_message,
            'fallback_message' => $this->fallback_message,
            'ai_enabled' => (bool) $this->ai_enabled,
            'ai_instructions' => $this->ai_instructions,
            'followup_enabled' => (bool) $this->followup_enabled,
            'followup_message' => $this->followup_message,
            'phone_number' => $this->whenLoaded('phoneNumber', fn () => $this->phoneNumber ? [
                'id' => $this->phoneNumber->uuid,
                'display_phone_number' => $this->phoneNumber->display_phone_number,
            ] : null),
            'rules_count' => $this->whenCounted('rules'),
            'rules' => $this->whenLoaded('rules', fn () => $this->rules->map(fn ($rule) => [
                'id' => $rule->uuid,
                'keyword' => $rule->keyword,
                'match_type' => $rule->match_type,
                'response_text' => $rule->response_text,
                'response_type' => $rule->response_type,
                'template_name' => $rule->template_name,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
