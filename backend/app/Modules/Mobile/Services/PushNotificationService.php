<?php

namespace App\Modules\Mobile\Services;

use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends push notifications to a tenant's registered mobile devices.
 *
 * Providers:
 *   - Expo tokens — unchanged legacy path (no credentials needed).
 *   - FCM tokens — FCM HTTP v1 when PUSH_FCM_ENABLED=true and a service-account
 *     JSON is readable (new); otherwise the old legacy sender (unchanged).
 *
 * Everything here is best-effort: a failure, timeout or misconfiguration never
 * propagates to the caller (webhook intake, review sync, chatbot hand-off). One
 * bad token never blocks the others.
 */
class PushNotificationService
{
    private const EXPO_ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    private const FCM_LEGACY_ENDPOINT = 'https://fcm.googleapis.com/fcm/send';
    private const FCM_V1_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const OAUTH_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const ACCESS_TOKEN_CACHE_KEY = 'push:fcm_v1_access_token';
    private const HTTP_TIMEOUT = 8;

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Tenant-wide push (unchanged contract — used by legacy callers and as the
     * fall-back when targeted recipients are disabled).
     *
     * @param array<string, mixed> $data
     */
    public function notifyTenant(int $tenantId, string $title, string $body, array $data = []): void
    {
        try {
            $tokens = DeviceToken::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->get(['token', 'provider']);

            $this->send($tokens, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('push: notifyTenant failed: ' . $e->getMessage());
        }
    }

    /**
     * Push to specific users' devices only.
     *
     * @param array<int,int> $userIds
     * @param array<string, mixed> $data
     */
    public function notifyUsers(int $tenantId, array $userIds, string $title, string $body, array $data = []): void
    {
        try {
            $userIds = array_values(array_unique(array_filter($userIds)));
            if ($userIds === []) {
                return;
            }

            $tokens = DeviceToken::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('user_id', $userIds)
                ->get(['token', 'provider']);

            $this->send($tokens, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('push: notifyUsers failed: ' . $e->getMessage());
        }
    }

    /**
     * Event push for a WhatsApp conversation (new message / hand-off). When
     * targeting is on, only users who may see the chat get it (and an agent only
     * for a chat assigned to them); otherwise it's tenant-wide (old behavior).
     *
     * @param array<string, mixed> $data
     */
    public function notifyConversation(
        Conversation $conversation,
        string $title,
        string $body,
        array $data = [],
        string $permission = 'conversations.view',
        ?int $excludeUserId = null,
    ): void {
        try {
            if (! config('services.push.targeted')) {
                $this->notifyTenant($conversation->tenant_id, $title, $body, $data);

                return;
            }

            $userIds = $this->conversationRecipients($conversation, $permission, $excludeUserId);
            $this->notifyUsers($conversation->tenant_id, $userIds, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('push: notifyConversation failed: ' . $e->getMessage());
        }
    }

    /**
     * Event push for a new Google review. Targeted → owners/managers only
     * (Eydia has no per-user GBP permission); otherwise tenant-wide.
     *
     * @param array<string, mixed> $data
     */
    public function notifyGbpReview(int $tenantId, string $title, string $body, array $data = []): void
    {
        try {
            if (! config('services.push.targeted')) {
                $this->notifyTenant($tenantId, $title, $body, $data);

                return;
            }

            $this->notifyUsers($tenantId, $this->gbpRecipients($tenantId), $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('push: notifyGbpReview failed: ' . $e->getMessage());
        }
    }

    // ── Recipient resolution ────────────────────────────────────────────────

    /** @return array<int,int> */
    private function conversationRecipients(Conversation $conversation, string $permission, ?int $excludeUserId): array
    {
        $users = User::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->get();

        $ids = [];
        foreach ($users as $user) {
            if ($user->is_super_admin) {
                continue; // super admins never get tenant-wide pushes
            }
            if ($excludeUserId !== null && $user->id === $excludeUserId) {
                continue; // don't notify the user who caused the event
            }
            if (! $this->userCan($user, $permission)) {
                continue;
            }
            // An agent (no conversations.assign) only gets a chat assigned to them;
            // owners/managers (who can assign) get every chat.
            if (! $this->userCan($user, 'conversations.assign')
                && (int) $conversation->assigned_agent_id !== (int) $user->id) {
                continue;
            }
            $ids[] = $user->id;
        }

        return $ids;
    }

    /** @return array<int,int> */
    private function gbpRecipients(int $tenantId): array
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->filter(fn (User $u) => ! $u->is_super_admin && $this->userHasAnyRole($u, ['tenant-owner', 'manager']))
            ->pluck('id')
            ->all();
    }

    private function userCan(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission, 'api');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @param array<int,string> $roles */
    private function userHasAnyRole(User $user, array $roles): bool
    {
        try {
            return $user->hasAnyRole($roles);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Sending ─────────────────────────────────────────────────────────────

    /** @param Collection<int,DeviceToken> $tokens */
    private function send(Collection $tokens, string $title, string $body, array $data): void
    {
        if ($tokens->isEmpty()) {
            return;
        }

        $body = Str::limit($body, 120);
        $data = $this->stringifyData($data);
        $collapse = $this->collapseKey($data);

        $expo = $tokens->where('provider', 'expo')->pluck('token')->all();
        $fcm = $tokens->where('provider', 'fcm');

        if (! empty($expo)) {
            $this->sendExpo($expo, $title, $body, $data);
        }

        if ($fcm->isNotEmpty()) {
            if (config('services.fcm.v1_enabled')) {
                $this->sendFcmV1($fcm, $title, $body, $data, $collapse);
            } else {
                $this->sendFcmLegacy($fcm->pluck('token')->all(), $title, $body, $data);
            }
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

        foreach (array_chunk($messages, 100) as $chunk) {
            try {
                $res = Http::timeout(15)->acceptJson()->post(self::EXPO_ENDPOINT, $chunk);
                if ($res->failed()) {
                    Log::warning('push: Expo send failed', ['status' => $res->status()]);
                }
            } catch (\Throwable $e) {
                Log::warning('push: Expo send error: ' . $e->getMessage());
            }
        }
    }

    /** Legacy FCM (unchanged — only used when PUSH_FCM_ENABLED is false). */
    private function sendFcmLegacy(array $tokens, string $title, string $body, array $data): void
    {
        $key = config('services.fcm.key');
        if (! $key) {
            return;
        }

        foreach (array_chunk($tokens, 1000) as $chunk) {
            try {
                $res = Http::withHeaders(['Authorization' => 'key=' . $key])
                    ->timeout(15)
                    ->post(self::FCM_LEGACY_ENDPOINT, [
                        'registration_ids' => $chunk,
                        'notification' => ['title' => $title, 'body' => $body, 'sound' => 'default'],
                        'data' => $data,
                    ]);
                if ($res->failed()) {
                    Log::warning('push: FCM legacy send failed', ['status' => $res->status()]);
                }
            } catch (\Throwable $e) {
                Log::warning('push: FCM legacy send error: ' . $e->getMessage());
            }
        }
    }

    /**
     * FCM HTTP v1 — one request per token (v1 has no multi-token batch), run in
     * small concurrent pools. Dead tokens are pruned; other errors are logged.
     *
     * @param Collection<int,DeviceToken> $tokens
     * @param array<string,string> $data
     */
    private function sendFcmV1(Collection $tokens, string $title, string $body, array $data, string $collapse): void
    {
        $projectId = $this->fcmProjectId();
        $access = $this->fcmAccessToken();
        if (! $projectId || ! $access) {
            return; // not configured / could not authenticate → skip silently
        }

        $url = sprintf(self::FCM_V1_ENDPOINT, $projectId);

        foreach ($tokens->chunk(100) as $chunk) {
            try {
                $responses = Http::pool(fn (Pool $pool) => $chunk
                    ->map(fn (DeviceToken $t) => $pool->as($t->token)
                        ->withToken($access)
                        ->acceptJson()
                        ->timeout(self::HTTP_TIMEOUT)
                        ->post($url, ['message' => $this->v1Message($t->token, $title, $body, $data, $collapse)]))
                    ->all());
            } catch (\Throwable $e) {
                Log::warning('push: FCM v1 pool error: ' . $e->getMessage());
                continue;
            }

            foreach ($chunk as $t) {
                $res = $responses[$t->token] ?? null;
                if ($res instanceof Response) {
                    if (! $res->successful()) {
                        $this->handleFcmV1Error($t->token, $res);
                    }
                } elseif ($res instanceof \Throwable) {
                    Log::warning('push: FCM v1 transport error', ['status' => 'transport']);
                }
            }
        }
    }

    private function handleFcmV1Error(string $token, Response $res): void
    {
        $errStatus = (string) $res->json('error.status');
        $dead = $res->status() === 404
            || in_array($errStatus, ['UNREGISTERED', 'NOT_FOUND'], true)
            || ($res->status() === 400 && $errStatus === 'INVALID_ARGUMENT');

        if ($dead) {
            DeviceToken::withoutGlobalScopes()->where('token', $token)->delete();

            return;
        }

        // Never log the token or any secret — just the Meta-safe status.
        Log::warning('push: FCM v1 send failed', ['status' => $res->status(), 'error' => $errStatus]);
    }

    /** @param array<string,string> $data */
    private function v1Message(string $token, string $title, string $body, array $data, string $collapse): array
    {
        return [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $data,
            'android' => [
                'priority' => 'HIGH',
                'collapse_key' => $collapse,
                'notification' => ['sound' => 'default', 'tag' => $collapse],
            ],
            'apns' => [
                'headers' => ['apns-collapse-id' => $collapse],
                'payload' => ['aps' => ['sound' => 'default']],
            ],
        ];
    }

    // ── Service-account auth (no SDK — openssl_sign + token exchange) ─────────

    /** @return array<string,mixed>|null */
    private function credentials(): ?array
    {
        $path = config('services.fcm.credentials_path');
        if (! $path || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)
            || empty($json['client_email'])
            || empty($json['private_key'])
            || empty($json['project_id'])) {
            return null;
        }

        return $json;
    }

    private function fcmProjectId(): ?string
    {
        return $this->credentials()['project_id'] ?? null;
    }

    private function fcmAccessToken(): ?string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $creds = $this->credentials();
        if (! $creds) {
            return null;
        }

        $token = $this->mintAccessToken($creds);
        if ($token) {
            // Google tokens last 1h; cache for 55 min so we never send an expired one.
            Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $token, now()->addMinutes(55));
        }

        return $token;
    }

    /** @param array<string,mixed> $creds */
    private function mintAccessToken(array $creds): ?string
    {
        try {
            $now = time();
            $jwtHeader = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $jwtClaim = $this->base64Url(json_encode([
                'iss' => $creds['client_email'],
                'scope' => self::FCM_SCOPE,
                'aud' => self::OAUTH_TOKEN_ENDPOINT,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signingInput = $jwtHeader . '.' . $jwtClaim;
            $signature = '';
            if (! openssl_sign($signingInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
                Log::warning('push: FCM JWT signing failed');

                return null;
            }

            $jwt = $signingInput . '.' . $this->base64Url($signature);

            $res = Http::asForm()->timeout(self::HTTP_TIMEOUT)->post(self::OAUTH_TOKEN_ENDPOINT, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($res->failed()) {
                Log::warning('push: FCM token exchange failed', ['status' => $res->status()]);

                return null;
            }

            $token = $res->json('access_token');

            return is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable $e) {
            Log::warning('push: FCM token mint error: ' . $e->getMessage());

            return null;
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * FCM v1 requires every data value to be a string, or the request is rejected.
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return $out;
    }

    /** Collapse pushes for the same chat/event so 20 messages don't stack 20 cards. */
    private function collapseKey(array $data): string
    {
        return (string) ($data['conversation_id'] ?? $data['id'] ?? $data['type'] ?? 'eydia');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
