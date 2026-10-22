<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\WhatsappPhoneNumber;
use App\Support\Services\BaseService;

/**
 * Tenant-facing send seam. Resolves the right provider for a phone number's WABA
 * and shapes Cloud API message payloads. Returns Meta's raw result (the wamid
 * lives at data.messages[0].id). Persisting Message/Conversation rows is Phase 8.
 */
class WhatsAppMessageService extends BaseService
{
    public function __construct(private readonly WhatsAppProviderFactory $factory) {}

    public function sendText(WhatsappPhoneNumber $phone, string $to, string $body, bool $previewUrl = false): array
    {
        return $this->provider($phone)->sendMessage($phone->phone_number_id, $to, [
            'type' => 'text',
            'text' => ['preview_url' => $previewUrl, 'body' => $body],
        ]);
    }

    /**
     * Send an interactive message with up to 3 quick-reply buttons. Falls back
     * to a plain text message when no valid button is given. WhatsApp limits:
     * body ≤1024 chars, 3 buttons, each title ≤20 chars.
     *
     * @param  array<int,string>  $buttons
     */
    public function sendButtons(WhatsappPhoneNumber $phone, string $to, string $body, array $buttons): array
    {
        $rows = [];
        foreach (array_values($buttons) as $i => $title) {
            $title = trim((string) $title);
            if ($title === '' || count($rows) >= 3) {
                continue;
            }
            $rows[] = ['type' => 'reply', 'reply' => ['id' => 'btn_' . $i, 'title' => mb_substr($title, 0, 20)]];
        }

        if ($rows === []) {
            return $this->sendText($phone, $to, $body);
        }

        return $this->provider($phone)->sendMessage($phone->phone_number_id, $to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['buttons' => $rows],
            ],
        ]);
    }

    /**
     * @param  array  $components  Cloud API template components (body/header/button params)
     */
    public function sendTemplate(WhatsappPhoneNumber $phone, string $to, string $templateName, string $language, array $components = []): array
    {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];

        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->provider($phone)->sendTemplate($phone->phone_number_id, $to, $template);
    }

    public function sendMedia(WhatsappPhoneNumber $phone, string $to, string $mediaType, string $filePath, string $mime, ?string $caption = null, ?string $fileName = null): array
    {
        $provider = $this->provider($phone);
        $uploadResult = $provider->uploadMedia($phone->phone_number_id, $filePath, $mime);
        $mediaId = $uploadResult['id'] ?? null;

        if (! $mediaId) {
            throw new \RuntimeException('Media upload failed — no media ID returned.');
        }

        $mediaPayload = ['id' => $mediaId];
        if ($caption) {
            $mediaPayload['caption'] = $caption;
        }
        if ($mediaType === 'document' && $fileName) {
            $mediaPayload['filename'] = $fileName;
        }

        return $provider->sendMessage($phone->phone_number_id, $to, [
            'type' => $mediaType,
            $mediaType => $mediaPayload,
        ]);
    }

    /** Upload a local file to Meta and return its media id (for template headers). */
    public function uploadMediaFile(WhatsappPhoneNumber $phone, string $path, string $mime): ?string
    {
        $result = $this->provider($phone)->uploadMedia($phone->phone_number_id, $path, $mime);

        return $result['id'] ?? null;
    }

    /** Download media bytes by Meta media id. Returns ['content','mime_type',...]. */
    public function downloadMedia(WhatsappPhoneNumber $phone, string $mediaId): array
    {
        return $this->provider($phone)->downloadMedia($mediaId);
    }

    /** Convenience: extract the wamid from a send result, or null. */
    public function wamid(array $result): ?string
    {
        return $result['messages'][0]['id'] ?? null;
    }

    private function provider(WhatsappPhoneNumber $phone)
    {
        $waba = $phone->businessAccount()->firstOrFail();

        return $this->factory->for($waba);
    }
}
