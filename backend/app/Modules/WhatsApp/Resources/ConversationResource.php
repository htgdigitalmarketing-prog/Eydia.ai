<?php

namespace App\Modules\WhatsApp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'unread_count' => $this->unread_count,
            'is_bot_active' => $this->is_bot_active,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->last_message_preview,
            'window_expires_at' => $this->window_expires_at?->toIso8601String(),
            'window_open' => $this->windowIsOpen(),
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->uuid,
                'name' => $this->contact->name,
                'phone' => $this->contact->phone,
                'wa_id' => $this->contact->wa_id,
                'is_hot' => (bool) $this->contact->is_hot,
                'hot_score' => $this->contact->hot_score,
                'hot_reason' => $this->contact->hot_reason,
            ]),
            'phone_number' => $this->whenLoaded('phoneNumber', fn () => [
                'id' => $this->phoneNumber->uuid,
                'display_phone_number' => $this->phoneNumber->display_phone_number,
                'verified_name' => $this->phoneNumber->verified_name,
            ]),
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn () => [
                'id' => $this->assignedAgent->uuid,
                'name' => $this->assignedAgent->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
