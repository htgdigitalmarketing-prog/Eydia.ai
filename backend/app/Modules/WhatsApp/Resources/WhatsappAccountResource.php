<?php

namespace App\Modules\WhatsApp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WABA shape — deliberately never exposes access_token.
 */
class WhatsappAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'waba_id' => $this->waba_id,
            'name' => $this->name,
            'currency' => $this->currency,
            'status' => $this->status,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'phone_numbers' => WhatsappNumberResource::collection($this->whenLoaded('phoneNumbers')),
        ];
    }
}
