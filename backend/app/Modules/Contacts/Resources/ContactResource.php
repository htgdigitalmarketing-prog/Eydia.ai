<?php

namespace App\Modules\Contacts\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'phone' => $this->phone,
            'wa_id' => $this->wa_id,
            'name' => $this->name,
            'email' => $this->email,
            'company' => $this->company,
            // Plain text, same as company (e.g. "vip" or "vip, delhi")
            'tag_list' => $this->tag_list,
            'source' => $this->source,
            'language' => $this->language,
            'country' => $this->country,
            'is_hot' => (bool) $this->is_hot,
            'hot_score' => $this->hot_score,
            'hot_reason' => $this->hot_reason,
            'hot_at' => $this->hot_at,
            'is_blocked' => $this->is_blocked,
            'opted_out' => $this->opted_out,
            'last_interaction_at' => $this->last_interaction_at?->toIso8601String(),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
                'id' => $t->uuid,
                'name' => $t->name,
                'color' => $t->color,
            ])),
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn () => $this->assignedAgent ? [
                'id' => $this->assignedAgent->uuid,
                'name' => $this->assignedAgent->name,
            ] : null),
            'conversations_count' => $this->whenLoaded('conversations', fn () => $this->conversations->count()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
