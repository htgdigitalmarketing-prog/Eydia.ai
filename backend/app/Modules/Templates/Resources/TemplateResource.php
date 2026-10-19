<?php

namespace App\Modules\Templates\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'quality_score' => $this->quality_score,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'components' => $this->whenLoaded('components', fn () => $this->components->map(fn ($c) => [
                'type' => $c->type,
                'format' => $c->format,
                'text' => $c->text,
                'buttons' => $c->buttons,
            ])),
            'waba' => $this->whenLoaded('businessAccount', fn () => [
                'id' => $this->businessAccount->uuid,
                'name' => $this->businessAccount->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
