<?php

namespace App\Modules\WhatsApp\Controllers;

use App\Models\WebhookEvent;
use App\Modules\WhatsApp\Jobs\ProcessWhatsAppWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController
{
    /**
     * Meta webhook verification (GET). Returns hub.challenge as plain text.
     * Meta sends: hub.mode, hub.verify_token, hub.challenge — Laravel converts
     * dots to underscores in query keys.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $this->isValidVerifyToken($token)) {
            Log::info('WhatsApp webhook verified.');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed.', [
            'mode' => $mode,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Receive webhook events (POST). Verifies HMAC signature, stores raw payload,
     * dispatches async processing, and returns 200 immediately.
     */
    public function receive(Request $request): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            Log::warning('WhatsApp webhook signature verification failed.');
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $body = $request->all();

        if (($body['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['status' => 'ignored'], 200);
        }

        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                foreach ($value['messages'] ?? [] as $message) {
                    $eventKey = 'msg:' . ($message['id'] ?? uniqid('msg_'));
                    $this->storeAndDispatch($eventKey, 'message', [
                        'metadata' => $value['metadata'] ?? [],
                        'message' => $message,
                        'contacts' => $value['contacts'] ?? [],
                    ]);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $eventKey = 'status:' . ($status['id'] ?? '') . ':' . ($status['status'] ?? '');
                    $this->storeAndDispatch($eventKey, 'status', [
                        'metadata' => $value['metadata'] ?? [],
                        'status' => $status,
                    ]);
                }
            }
        }

        return response()->json(['status' => 'received'], 200);
    }

    private function storeAndDispatch(string $eventKey, string $objectType, array $payload): void
    {
        $event = WebhookEvent::firstOrCreate(
            ['event_key' => $eventKey],
            [
                'source' => 'whatsapp',
                'object_type' => $objectType,
                'payload' => $payload,
                'processed' => false,
                'attempts' => 0,
            ]
        );

        if ($event->wasRecentlyCreated) {
            ProcessWhatsAppWebhook::dispatch($event);
        }
    }

    private function isValidVerifyToken(?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $primary = config('services.meta.verify_token');
        if ($primary && $token === $primary) {
            return true;
        }

        foreach (config('services.meta.verify_tokens', []) as $validToken) {
            if ($token === $validToken) {
                return true;
            }
        }

        return false;
    }

    private function verifySignature(Request $request): bool
    {
        $secrets = $this->getAllAppSecrets();
        if (empty($secrets)) {
            Log::warning('webhook: no META_APP_SECRET configured, rejecting request');
            return false;
        }

        $signature = $request->header('X-Hub-Signature-256');
        if (empty($signature)) {
            return false;
        }

        $content = $request->getContent();
        foreach ($secrets as $secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $content, $secret);
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function getAllAppSecrets(): array
    {
        $secrets = [];

        $primary = config('services.meta.app_secret');
        if (! empty($primary)) {
            $secrets[] = $primary;
        }

        foreach (config('services.meta.app_secrets', []) as $secret) {
            if (! in_array($secret, $secrets, true)) {
                $secrets[] = $secret;
            }
        }

        return $secrets;
    }
}
