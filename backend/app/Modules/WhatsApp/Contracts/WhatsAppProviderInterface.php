<?php

namespace App\Modules\WhatsApp\Contracts;

/**
 * The ONLY seam through which the application talks to WhatsApp.
 * Nothing outside a provider implementation may call graph.facebook.com.
 * Bound in a service provider: Meta in production, Fake in tests/local.
 */
interface WhatsAppProviderInterface
{
    public function sendMessage(string $phoneNumberId, string $to, array $message): array;

    public function sendTemplate(string $phoneNumberId, string $to, array $template): array;

    public function getTemplates(string $wabaId): array;

    public function createTemplate(string $wabaId, array $payload): array;

    public function deleteTemplate(string $wabaId, string $name): array;

    public function getPhoneNumber(string $phoneNumberId): array;

    public function registerPhoneNumber(string $phoneNumberId, string $pin): array;

    public function subscribeApp(string $wabaId): array;

    public function uploadMedia(string $phoneNumberId, string $path, string $mime): array;

    public function downloadMedia(string $mediaId): array;

    /**
     * Upload a sample image/video/document for a media-header template and return
     * Meta's resumable-upload handle (used as the HEADER example.header_handle).
     */
    public function uploadTemplateMedia(string $appId, string $content, string $mime, string $fileName): string;
}
