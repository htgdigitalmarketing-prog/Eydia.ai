<?php

namespace App\Modules\WhatsApp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'direction' => $this->direction,
            'type' => $this->type,
            'body' => $this->body,
            'status' => $this->status,
            'external_message_id' => $this->external_message_id,
            'sender' => $this->whenLoaded('sender', fn () => [
                'id' => $this->sender->uuid,
                'name' => $this->sender->name,
            ]),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'type' => $a->type,
                'mime_type' => $a->mime_type,
                'file_name' => $a->file_name,
                'file_size' => $a->file_size,
                'caption' => $a->caption,
                'url' => $a->storage_path ? url("api/v1/whatsapp/media/{$a->uuid}") : null,
            ])),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
