<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\WhatsappBusinessAccount;
use App\Models\WhatsappPhoneNumber;
use App\Support\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Handles the production "Connect WhatsApp" flow (Facebook Login for Business /
 * Embedded Signup). The frontend SDK returns an authorization `code` plus the
 * selected `waba_id` and `phone_number_id`; here we exchange the code for a
 * business access token, subscribe our app to the WABA, and persist everything
 * (token encrypted at rest).
 *
 * Requires META_APP_ID / META_APP_SECRET to be configured. Without them, the
 * controller falls back to manual connect (dev/demo).
 */
class WhatsAppEmbeddedSignupService extends BaseService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.meta.app_id') && (bool) config('services.meta.app_secret');
    }

    public function connect(int $tenantId, string $code, string $wabaId, ?string $phoneNumberId = null): WhatsappBusinessAccount
    {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages([
                'code' => ['Embedded Signup is not configured. Set META_APP_ID and META_APP_SECRET, or use manual connect.'],
            ]);
        }

        $token = $this->exchangeCodeForToken($code);
        $this->subscribeApp($wabaId, $token);

        return DB::transaction(function () use ($tenantId, $wabaId, $phoneNumberId, $token) {
            $meta = $this->fetchWaba($wabaId, $token);

            $waba = WhatsappBusinessAccount::updateOrCreate(
                ['tenant_id' => $tenantId, 'waba_id' => $wabaId],
                [
                    'name' => $meta['name'] ?? 'WhatsApp Business Account',
                    'currency' => $meta['currency'] ?? null,
                    'timezone_id' => $meta['timezone_id'] ?? null,
                    'access_token' => $token,
                    'status' => 'connected',
                    'connected_at' => now(),
                ],
            );

            // List the WABA's numbers. If that comes back empty (common right
            // after creating a new number, or when the list endpoint is limited)
            // but Meta gave us the selected phone_number_id, fetch it directly so
            // the number still registers.
            $numbers = $this->fetchPhoneNumbers($wabaId, $token);
            if ($numbers === [] && $phoneNumberId) {
                $single = $this->fetchSinglePhoneNumber($phoneNumberId, $token);
                if ($single) {
                    $numbers = [$single];
                }
            }

            foreach ($numbers as $number) {
                if (empty($number['id'])) {
                    continue;
                }
                $phone = WhatsappPhoneNumber::withoutGlobalScopes()
                    ->withTrashed()
                    ->updateOrCreate(
                        ['phone_number_id' => $number['id']],
                        [
                            'tenant_id' => $tenantId,
                            'whatsapp_business_account_id' => $waba->id,
                            'display_phone_number' => $number['display_phone_number'] ?? '',
                            'verified_name' => $number['verified_name'] ?? null,
                            'quality_rating' => $number['quality_rating'] ?? null,
                            'status' => 'connected',
                            'is_default' => $number['id'] === $phoneNumberId,
                        ],
                    );

                if ($phone->trashed()) {
                    $phone->restore();
                }
            }

            return $waba->load('phoneNumbers');
        });
    }

    private function graphBase(): string
    {
        $version = config('services.meta.api_version', 'v23.0');
        $base = rtrim((string) config('services.meta.graph_base', 'https://graph.facebook.com'), '/');

        return "{$base}/{$version}";
    }

    private function exchangeCodeForToken(string $code): string
    {
        $res = Http::acceptJson()->get("{$this->graphBase()}/oauth/access_token", [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'code' => $code,
        ]);

        $token = $res->json('access_token');

        if (! $res->successful() || ! $token) {
            throw ValidationException::withMessages([
                'code' => ['Could not complete WhatsApp sign-up. Please try connecting again.'],
            ]);
        }

        return $token;
    }

    private function subscribeApp(string $wabaId, string $token): void
    {
        Http::withToken($token)->acceptJson()->post("{$this->graphBase()}/{$wabaId}/subscribed_apps");
    }

    private function fetchWaba(string $wabaId, string $token): array
    {
        return Http::withToken($token)->acceptJson()
            ->get("{$this->graphBase()}/{$wabaId}", ['fields' => 'name,currency,timezone_id'])
            ->json() ?? [];
    }

    private function fetchPhoneNumbers(string $wabaId, string $token): array
    {
        $res = Http::withToken($token)->acceptJson()
            ->get("{$this->graphBase()}/{$wabaId}/phone_numbers", [
                'fields' => 'id,display_phone_number,verified_name,quality_rating',
            ]);

        if (! $res->successful()) {
            \Illuminate\Support\Facades\Log::warning('wa embedded signup: phone_numbers list failed', [
                'waba_id' => $wabaId, 'status' => $res->status(), 'error' => $res->json('error.message'),
            ]);
        }

        return $res->json('data') ?? [];
    }

    /** Fetch one phone number's details by id (fallback when the list is empty). */
    private function fetchSinglePhoneNumber(string $phoneNumberId, string $token): ?array
    {
        $res = Http::withToken($token)->acceptJson()
            ->get("{$this->graphBase()}/{$phoneNumberId}", [
                'fields' => 'id,display_phone_number,verified_name,quality_rating',
            ]);

        if (! $res->successful() || ! $res->json('id')) {
            \Illuminate\Support\Facades\Log::warning('wa embedded signup: single phone fetch failed', [
                'phone_number_id' => $phoneNumberId, 'status' => $res->status(), 'error' => $res->json('error.message'),
            ]);

            return null;
        }

        return $res->json();
    }
}
