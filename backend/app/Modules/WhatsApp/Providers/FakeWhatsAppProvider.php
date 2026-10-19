<?php

namespace App\Modules\WhatsApp\Providers;

use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use Illuminate\Support\Str;

/**
 * Deterministic in-memory provider for tests and local dev.
 * Records every call so tests can assert against them. Never touches the network.
 */
class FakeWhatsAppProvider implements WhatsAppProviderInterface
{
    public array $sent = [];

    private function fakeWamid(): string
    {
        return 'wamid.FAKE' . Str::upper(Str::random(20));
    }

    public function sendMessage(string $phoneNumberId, string $to, array $message): array
    {
        $this->sent[] = compact('phoneNumberId', 'to', 'message');
        return ['messages' => [['id' => $this->fakeWamid()]]];
    }

    public function sendTemplate(string $phoneNumberId, string $to, array $template): array
    {
        $this->sent[] = compact('phoneNumberId', 'to', 'template');
        return ['messages' => [['id' => $this->fakeWamid()]]];
    }

    public function getTemplates(string $wabaId): array
    {
        return ['data' => []];
    }

    public function createTemplate(string $wabaId, array $payload): array
    {
        $this->sent[] = compact('wabaId', 'payload');
        return ['id' => 'template_' . Str::random(12), 'status' => 'PENDING', 'category' => $payload['category'] ?? 'UTILITY'];
    }

    public function deleteTemplate(string $wabaId, string $name): array
    {
        $this->sent[] = ['deleted' => compact('wabaId', 'name')];
        return ['success' => true];
    }

    public function getPhoneNumber(string $phoneNumberId): array
    {
        return ['id' => $phoneNumberId, 'display_phone_number' => '15550000000', 'quality_rating' => 'GREEN'];
    }

    public function registerPhoneNumber(string $phoneNumberId, string $pin): array
    {
        return ['success' => true];
    }

    public function subscribeApp(string $wabaId): array
    {
        $this->sent[] = ['subscribed' => $wabaId];
        return ['success' => true];
    }

    public function uploadMedia(string $phoneNumberId, string $path, string $mime): array
    {
        return ['id' => 'media_' . Str::random(12)];
    }

    public function downloadMedia(string $mediaId): array
    {
        return [
            'content' => 'fake-binary-content',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'sha256' => 'fake_sha256',
        ];
    }

    public function uploadTemplateMedia(string $appId, string $content, string $mime, string $fileName): string
    {
        $this->sent[] = ['template_media' => compact('appId', 'mime', 'fileName')];
        return 'FAKE_HANDLE_' . Str::random(16);
    }
}
