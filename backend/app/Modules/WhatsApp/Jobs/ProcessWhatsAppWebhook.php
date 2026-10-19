<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Models\WebhookEvent;
use App\Modules\WhatsApp\Services\WhatsAppWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60];

    public function __construct(public readonly WebhookEvent $event) {}

    public function handle(WhatsAppWebhookService $service): void
    {
        $event = $this->event;

        if ($event->processed) {
            return;
        }

        $event->increment('attempts');

        try {
            match ($event->object_type) {
                'message' => $service->handleInboundMessage($event),
                'status' => $service->handleStatusUpdate($event),
                default => Log::info("Unhandled webhook type: {$event->object_type}"),
            };

            $event->update(['processed' => true, 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $event->update(['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
