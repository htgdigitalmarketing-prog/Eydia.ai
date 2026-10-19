<?php

namespace App\Modules\WhatsApp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsappNumberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'phone_number_id' => $this->phone_number_id,
            'display_phone_number' => $this->display_phone_number,
            'verified_name' => $this->verified_name,
            'quality_rating' => $this->quality_rating,
            'status' => $this->status,
            'is_default' => (bool) $this->is_default,
            'is_registered' => (bool) $this->is_registered,
            'waba' => $this->whenLoaded('businessAccount', fn () => [
                'id' => $this->businessAccount->uuid,
                'name' => $this->businessAccount->name,
                'waba_id' => $this->businessAccount->waba_id,
                'status' => $this->businessAccount->status,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
