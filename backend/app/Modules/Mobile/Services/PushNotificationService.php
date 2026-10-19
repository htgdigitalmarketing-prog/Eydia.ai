<?php

namespace App\Modules\Mobile\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications to a tenant's registered mobile devices. Supports
 * Expo push tokens (no server credentials needed) and, when FCM_SERVER_KEY is
 * set, legacy FCM tokens. Entirely best-effort: a failure here never affects
 * the caller (e.g. inbound-message intake).
 */
class PushNotificationService
{
    private const EXPO_ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/fcm/send';

    /**
     * @param array<string, mixed> $data extra key/values delivered with the push
     */
    public function notifyTenant(int $tenantId, string $title, string $body, array $data = []): void
    {
        try {
            $tokens = DeviceToken::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->get(['token', 'provider']);

            if ($tokens->isEmpty()) {
                return;
            }

            $expo = $tokens->where('provider', 'expo')->pluck('token')->all();
            $fcm = $tokens->where('provider', 'fcm')->pluck('token')->all();

            if (! empty($expo)) {
                $this->sendExpo($expo, $title, $body, $data);
            }
            if (! empty($fcm)) {
                $this->sendFcm($fcm, $title, $body, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('push: notifyTenant failed: ' . $e->getMessage());
        }
    }

    /** @param array<int,string> $tokens */
    private function sendExpo(array $tokens, string $title, string $body, array $data): void
    {
        $messages = array_map(fn ($token) => [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'data' => $data,
        ], $tokens);

        // Expo accepts up to 100 messages per request.
        foreach (array_chunk($messages, 100) as $chunk) {
            $res = Http::timeout(15)->acceptJson()->post(self::EXPO_ENDPOINT, $chunk);
            if ($res->failed()) {
                Log::warning('push: Expo send failed', ['status' => $res->status()]);
            }
        }
    }

    /** @param array<int,string> $tokens */
    private function sendFcm(array $tokens, string $title, string $body, array $data): void
    {
        $key = config('services.fcm.key');
        if (! $key) {
            return; // FCM not configured — skip silently
        }

        foreach (array_chunk($tokens, 1000) as $chunk) {
            $res = Http::withHeaders(['Authorization' => 'key=' . $key])
                ->timeout(15)
                ->post(self::FCM_ENDPOINT, [
                    'registration_ids' => $chunk,
                    'notification' => ['title' => $title, 'body' => $body, 'sound' => 'default'],
                    'data' => $data,
                ]);
            if ($res->failed()) {
                Log::warning('push: FCM send failed', ['status' => $res->status()]);
            }
        }
    }
}
