<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\WhatsappBusinessAccount;
use App\Models\WhatsappPhoneNumber;
use App\Support\Services\BaseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Connects and manages a tenant's WhatsApp Business Accounts (WABAs) and phone
 * numbers. Two connect paths:
 *   - Embedded Signup (production): handled by WhatsAppEmbeddedSignupService.
 *   - Manual connect (dev/demo, and valid for self-managed API setups): the
 *     tenant supplies their WABA id, phone_number_id, display number and a
 *     System User token. The token is encrypted at rest by the model cast.
 */
class WhatsAppAccountService extends BaseService
{
    public function __construct(private readonly WhatsAppProviderFactory $factory) {}

    /** @return Collection<int,WhatsappBusinessAccount> */
    public function accountsFor(int $tenantId): Collection
    {
        return WhatsappBusinessAccount::where('tenant_id', $tenantId)
            ->with('phoneNumbers')
            ->latest()
            ->get();
    }

    /** @return Collection<int,WhatsappPhoneNumber> */
    public function numbersFor(int $tenantId): Collection
    {
        return WhatsappPhoneNumber::where('tenant_id', $tenantId)
            ->with('businessAccount')
            ->latest()
            ->get();
    }

    /**
     * Manually attach a WABA + phone number. Idempotent-ish: a phone_number_id is
     * globally unique on Meta's side, so re-connecting an existing number is
     * rejected with a clear message.
     */
    public function connectManual(int $tenantId, array $data): WhatsappPhoneNumber
    {
        $existing = WhatsappPhoneNumber::withTrashed()
            ->where('phone_number_id', $data['phone_number_id'])
            ->first();

        if ($existing && $existing->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'phone_number_id' => ['This phone number is connected to another account.'],
            ]);
        }

        $phone = DB::transaction(function () use ($tenantId, $data, $existing) {
            $waba = WhatsappBusinessAccount::firstOrCreate(
                ['tenant_id' => $tenantId, 'waba_id' => $data['waba_id']],
                [
                    'name' => $data['name'] ?? 'WhatsApp Business Account',
                    'access_token' => $data['access_token'],
                    'status' => 'connected',
                    'connected_at' => now(),
                ],
            );

            if (($data['access_token'] ?? null) && $waba->wasRecentlyCreated === false) {
                $waba->update(['access_token' => $data['access_token'], 'status' => 'connected']);
            }

            if ($existing) {
                $existing->restore();
                $existing->update([
                    'whatsapp_business_account_id' => $waba->id,
                    'display_phone_number' => $data['display_phone_number'],
                    'verified_name' => $data['verified_name'] ?? $existing->verified_name,
                    'status' => 'connected',
                ]);
                return $existing->fresh('businessAccount');
            }

            $phone = WhatsappPhoneNumber::create([
                'tenant_id' => $tenantId,
                'whatsapp_business_account_id' => $waba->id,
                'phone_number_id' => $data['phone_number_id'],
                'display_phone_number' => $data['display_phone_number'],
                'verified_name' => $data['verified_name'] ?? null,
                'status' => 'connected',
                'is_default' => WhatsappPhoneNumber::where('tenant_id', $tenantId)->doesntExist(),
                'is_registered' => (bool) ($data['is_registered'] ?? false),
            ]);

            return $phone->load('businessAccount');
        });

        // Subscribe the app to the WABA so the token can send (best-effort — a
        // failure here must not block the connection).
        try {
            $this->subscribeApp($phone);
        } catch (\Throwable $e) {
            \Log::warning('whatsapp.subscribe_app.failed on connect: ' . $e->getMessage());
        }

        return $phone;
    }

    /**
     * Pull the latest phone metadata (display number, quality rating, verified
     * name, verification status) from Meta and persist it.
     */
    public function syncNumber(WhatsappPhoneNumber $phone): WhatsappPhoneNumber
    {
        $provider = $this->factory->for($phone->businessAccount()->firstOrFail());
        $data = $provider->getPhoneNumber($phone->phone_number_id);

        $phone->update(array_filter([
            'display_phone_number' => $data['display_phone_number'] ?? $phone->display_phone_number,
            'verified_name' => $data['verified_name'] ?? $phone->verified_name,
            'quality_rating' => $data['quality_rating'] ?? $phone->quality_rating,
            'code_verification_status' => $data['code_verification_status'] ?? $phone->code_verification_status,
        ], fn ($v) => $v !== null));

        return $phone->fresh('businessAccount');
    }

    /**
     * Subscribe the app to the number's WABA (required before the app's token can
     * send). Best-effort — returns the Meta result. Throws on hard failure so the
     * controller can surface it.
     */
    public function subscribeApp(WhatsappPhoneNumber $phone): array
    {
        $waba = $phone->businessAccount()->firstOrFail();
        return $this->factory->for($waba)->subscribeApp($waba->waba_id);
    }

    /** Register the phone on Cloud API with a 6-digit PIN (two-step verification). */
    public function registerNumber(WhatsappPhoneNumber $phone, string $pin): WhatsappPhoneNumber
    {
        $provider = $this->factory->for($phone->businessAccount()->firstOrFail());
        $provider->registerPhoneNumber($phone->phone_number_id, $pin);

        $phone->update(['is_registered' => true, 'status' => 'registered']);

        return $phone->fresh('businessAccount');
    }

    public function disconnect(WhatsappPhoneNumber $phone): void
    {
        $phone->update(['status' => 'disconnected']);
        $phone->delete(); // soft delete
    }
}
