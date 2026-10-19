<?php

namespace App\Modules\WhatsApp\Providers;

use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Modules\WhatsApp\Exceptions\WhatsAppApiException;
use App\Modules\WhatsApp\Support\Masker;
use App\Modules\WhatsApp\Support\MetaErrorMapper;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Production provider. Talks to the Meta Graph API (version pinned via
 * config('services.meta.api_version'), default v23.0). The System User token is
 * injected per WABA — never a 24h user token, never exposed to the client.
 *
 * Every call is timed, retried on transient failures, logged with secrets/PII
 * masked (via the injected $logger), and on error raises a WhatsAppApiException
 * carrying a user-safe message while keeping the raw Meta detail for logs.
 */
class MetaWhatsAppProvider implements WhatsAppProviderInterface
{
    /**
     * @param  Closure|null  $logger  fn(array $maskedLog): void — persists to api_logs
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly ?Closure $logger = null,
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
        private readonly int $retryDelayMs = 300,
    ) {}

    private function graph(): PendingRequest
    {
        return Http::withToken($this->token)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout($this->timeout)
            // Retry only transient errors; do NOT retry 4xx client errors.
            ->retry($this->retries, $this->retryDelayMs, function ($exception, $request) {
                return $exception instanceof ConnectionException
                    || ($exception->response ?? null)?->serverError();
            }, throw: false);
    }

    public function sendMessage(string $phoneNumberId, string $to, array $message): array
    {
        $body = array_merge(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to], $message);

        return $this->perform('POST', "/{$phoneNumberId}/messages", $body,
            fn (PendingRequest $g) => $g->post("/{$phoneNumberId}/messages", $body));
    }

    public function sendTemplate(string $phoneNumberId, string $to, array $template): array
    {
        $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => $template];

        return $this->perform('POST', "/{$phoneNumberId}/messages", $body,
            fn (PendingRequest $g) => $g->post("/{$phoneNumberId}/messages", $body));
    }

    public function getTemplates(string $wabaId): array
    {
        return $this->perform('GET', "/{$wabaId}/message_templates", ['limit' => 250],
            fn (PendingRequest $g) => $g->get("/{$wabaId}/message_templates", ['limit' => 250]));
    }

    public function createTemplate(string $wabaId, array $payload): array
    {
        return $this->perform('POST', "/{$wabaId}/message_templates", $payload,
            fn (PendingRequest $g) => $g->post("/{$wabaId}/message_templates", $payload));
    }

    public function deleteTemplate(string $wabaId, string $name): array
    {
        return $this->perform('DELETE', "/{$wabaId}/message_templates", ['name' => $name],
            fn (PendingRequest $g) => $g->delete("/{$wabaId}/message_templates", ['name' => $name]));
    }

    public function getPhoneNumber(string $phoneNumberId): array
    {
        $fields = 'id,display_phone_number,verified_name,quality_rating,throughput,code_verification_status,name_status';

        return $this->perform('GET', "/{$phoneNumberId}", ['fields' => $fields],
            fn (PendingRequest $g) => $g->get("/{$phoneNumberId}", ['fields' => $fields]));
    }

    public function registerPhoneNumber(string $phoneNumberId, string $pin): array
    {
        $body = ['messaging_product' => 'whatsapp', 'pin' => $pin];

        return $this->perform('POST', "/{$phoneNumberId}/register", $body,
            fn (PendingRequest $g) => $g->post("/{$phoneNumberId}/register", $body));
    }

    public function subscribeApp(string $wabaId): array
    {
        // Subscribe this app to the WABA — required before the app's token can
        // send messages on the WABA's numbers (a common cause of Meta #200).
        return $this->perform('POST', "/{$wabaId}/subscribed_apps", [],
            fn (PendingRequest $g) => $g->post("/{$wabaId}/subscribed_apps"));
    }

    public function uploadMedia(string $phoneNumberId, string $path, string $mime): array
    {
        return $this->perform('POST', "/{$phoneNumberId}/media", ['type' => $mime, 'file' => '<binary>'],
            fn (PendingRequest $g) => $g
                ->asMultipart()
                ->attach('file', file_get_contents($path), basename($path), ['Content-Type' => $mime])
                ->post("/{$phoneNumberId}/media", ['messaging_product' => 'whatsapp', 'type' => $mime]));
    }

    public function uploadTemplateMedia(string $appId, string $content, string $mime, string $fileName): string
    {
        // Meta resumable upload (two steps). Step 1: start a session.
        $start = Http::withToken($this->token)->baseUrl($this->baseUrl)->acceptJson()->timeout($this->timeout)
            ->post("/{$appId}/uploads", [
                'file_name' => $fileName,
                'file_length' => strlen($content),
                'file_type' => $mime,
            ]);

        $sessionId = $start->json('id');
        if ($start->failed() || ! $sessionId) {
            throw new WhatsAppApiException(
                userMessage: 'Could not start the sample upload with Meta. Please try a different file.',
                httpStatus: 502,
                technicalMessage: 'uploads start failed: ' . $start->body(),
            );
        }

        // Step 2: upload the bytes; Meta returns the handle in "h".
        $upload = Http::withHeaders(['Authorization' => 'OAuth ' . $this->token, 'file_offset' => '0'])
            ->baseUrl($this->baseUrl)->timeout(max($this->timeout, 60))
            ->withBody($content, $mime)
            ->post("/{$sessionId}");

        $handle = $upload->json('h');
        if ($upload->failed() || ! $handle) {
            throw new WhatsAppApiException(
                userMessage: 'Could not upload the sample file to Meta. Please try a smaller file.',
                httpStatus: 502,
                technicalMessage: 'uploads body failed: ' . $upload->body(),
            );
        }

        return $handle;
    }

    public function downloadMedia(string $mediaId): array
    {
        $meta = $this->perform('GET', "/{$mediaId}", [],
            fn (PendingRequest $g) => $g->get("/{$mediaId}"));

        $mediaUrl = $meta['url'] ?? null;
        if (! $mediaUrl) {
            throw new WhatsAppApiException(
                userMessage: 'Could not retrieve media URL.',
                httpStatus: 502,
                technicalMessage: "No url in media metadata for {$mediaId}",
            );
        }

        $response = Http::withToken($this->token)
            ->timeout($this->timeout)
            ->get($mediaUrl);

        if ($response->failed()) {
            throw new WhatsAppApiException(
                userMessage: 'Could not download media file.',
                httpStatus: 502,
                technicalMessage: "Media download failed: {$response->status()}",
            );
        }

        return [
            'content' => $response->body(),
            'mime_type' => $meta['mime_type'] ?? null,
            'file_size' => $meta['file_size'] ?? null,
            'sha256' => $meta['sha256'] ?? null,
        ];
    }

    /**
     * Execute a Graph call: time it, log it (masked), then either return the JSON
     * body or throw a mapped WhatsAppApiException.
     *
     * @param  Closure(PendingRequest): Response  $call
     */
    private function perform(string $method, string $path, array $bodyForLog, Closure $call): array
    {
        $startedAt = microtime(true);

        try {
            $response = $call($this->graph());
        } catch (ConnectionException $e) {
            $this->log($method, $path, $bodyForLog, null, null, $startedAt);
            throw new WhatsAppApiException(
                userMessage: 'Could not reach WhatsApp. Please try again in a moment.',
                httpStatus: 503,
                technicalMessage: $e->getMessage(),
                previous: $e,
            );
        }

        $json = $response->json() ?? [];
        $this->log($method, $path, $bodyForLog, $response->status(), $json, $startedAt);

        if ($response->failed()) {
            $error = $json['error'] ?? [];
            $code = isset($error['code']) ? (int) $error['code'] : null;

            throw new WhatsAppApiException(
                userMessage: MetaErrorMapper::message($code),
                metaCode: $code,
                httpStatus: MetaErrorMapper::httpStatus($code),
                raw: $json,
                technicalMessage: $error['message'] ?? "Meta API {$response->status()} on {$method} {$path}",
            );
        }

        return $json;
    }

    private function log(string $method, string $path, array $body, ?int $status, ?array $response, float $startedAt): void
    {
        if ($this->logger === null) {
            return;
        }

        ($this->logger)([
            'method' => $method,
            'endpoint' => $path,
            'status_code' => $status,
            'request' => Masker::payload($body),
            'response' => Masker::payload($response ?? []),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_code' => isset($response['error']['code']) ? (string) $response['error']['code'] : null,
        ]);
    }
}
