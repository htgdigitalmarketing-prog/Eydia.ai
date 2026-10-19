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

            foreach ($this->fetchPhoneNumbers($wabaId, $token) as $number) {
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
        return Http::withToken($token)->acceptJson()
            ->get("{$this->graphBase()}/{$wabaId}/phone_numbers", [
                'fields' => 'id,display_phone_number,verified_name,quality_rating',
            ])
            ->json('data') ?? [];
    }
}
