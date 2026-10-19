<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a login OTP over whatever channel is configured, trying in order:
 *   1. WhatsApp — sends an approved template (OTP_WHATSAPP_TEMPLATE, body param = code)
 *      from the user's own connected number. No extra provider needed.
 *   2. MSG91 SMS — if MSG91_AUTH_KEY is set.
 *   3. Log only — always logs the code so local/dev testing works even with no channel.
 *
 * Returns true only when a real channel accepted the message.
 */
class OtpSender
{
    public function __construct(private readonly WhatsAppMessageService $wa) {}

    public function send(?User $user, string $phone, string $code): bool
    {
        // Deliver to the FULL number on the user's account (with country code), not
        // the stripped 10-digit match key — WhatsApp/SMS need the country code.
        $to = $user && $user->phone
            ? preg_replace('/\D+/', '', $user->phone)
            : $phone;

        if ($this->sendViaWhatsApp($user, $to, $code)) {
            return true;
        }
        if ($this->sendViaMsg91($to, $code)) {
            return true;
        }

        Log::info("Login OTP for {$to}: {$code} (no delivery channel configured — set OTP_WHATSAPP_TEMPLATE or MSG91_AUTH_KEY)");

        return false;
    }

    private function sendViaWhatsApp(?User $user, string $phone, string $code): bool
    {
        $template = config('services.otp.whatsapp_template');
        if (! $template || ! $user || ! $user->tenant_id) {
            return false;
        }

        $number = $this->senderNumber($user);
        if (! $number) {
            return false;
        }

        // Authentication templates (the recommended OTP type) need the code on BOTH
        // the body and the copy-code/auto-fill button. Utility templates take only
        // the body. Controlled by OTP_WHATSAPP_TYPE (default: authentication).
        $components = [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
        ];
        if (config('services.otp.whatsapp_type', 'authentication') === 'authentication') {
            $components[] = [
                'type' => 'button',
                'sub_type' => config('services.otp.whatsapp_button_subtype', 'url'),
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $code]],
            ];
        }

        try {
            $this->wa->sendTemplate(
                $number,
                $phone,
                $template,
                config('services.otp.whatsapp_lang', 'en'),
                $components,
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('OTP WhatsApp send failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Which number sends the OTP. If OTP_WHATSAPP_PHONE_NUMBER_ID is set, every
     * login code goes from that one platform number (so the template is approved
     * on ONE account for all tenants). Otherwise it uses the user's own tenant's
     * connected number.
     */
    private function senderNumber(User $user): ?WhatsappPhoneNumber
    {
        $platformId = config('services.otp.whatsapp_phone_number_id');
        if ($platformId) {
            return WhatsappPhoneNumber::withoutGlobalScopes()
                ->where('phone_number_id', $platformId)
                ->first();
        }

        return WhatsappPhoneNumber::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('is_default')
            ->first();
    }

    private function sendViaMsg91(string $phone, string $code): bool
    {
        $key = config('services.msg91.key');
        if (! $key) {
            return false;
        }

        try {
            // MSG91 OTP API: sends the code via a pre-approved SMS template.
            $res = Http::timeout(15)->withHeaders(['authkey' => $key])
                ->post('https://control.msg91.com/api/v5/otp', [
                    'template_id' => config('services.msg91.template_id'),
                    'mobile' => $phone,
                    'otp' => $code,
                    'sender' => config('services.msg91.sender'),
                ]);

            if ($res->successful()) {
                return true;
            }
            Log::warning('OTP MSG91 send failed', ['status' => $res->status(), 'body' => $res->body()]);
        } catch (\Throwable $e) {
            Log::warning('OTP MSG91 send error: ' . $e->getMessage());
        }

        return false;
    }
}
