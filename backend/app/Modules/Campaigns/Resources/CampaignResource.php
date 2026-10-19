<?php

namespace App\Modules\Campaigns\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'status' => $this->status,
            'template' => $this->whenLoaded('template', fn () => $this->template ? [
                'id' => $this->template->uuid,
                'name' => $this->template->name,
                'language' => $this->template->language,
            ] : null),
            'phone_number' => $this->whenLoaded('phoneNumber', fn () => $this->phoneNumber ? [
                'id' => $this->phoneNumber->uuid,
                'display_phone_number' => $this->phoneNumber->display_phone_number,
            ] : null),
            'audience_filter' => $this->audience_filter,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'total_recipients' => $this->total_recipients,
            'sent_count' => $this->sent_count,
            'delivered_count' => $this->delivered_count,
            'read_count' => $this->read_count,
            'failed_count' => $this->failed_count,
            'replied_count' => $this->replied_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
