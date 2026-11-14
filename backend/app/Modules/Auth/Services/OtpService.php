<?php

namespace App\Modules\Auth\Services;

use App\Models\LoginOtp;
use App\Models\User;
use App\Modules\Auth\Actions\RegisterTenantAction;
use App\Modules\Auth\DTOs\RegisterData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues and checks one-time login codes for mobile-OTP sign-in. Codes are 6
 * digits, valid for 5 minutes, limited to 5 attempts, with a resend cooldown.
 * To avoid leaking which numbers are registered, requesting a code always looks
 * like it succeeded.
 */
class OtpService
{
    private const TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly OtpSender $sender,
        private readonly RegisterTenantAction $registerTenant,
    ) {}

    /**
     * Generate and send a code. Returns ['dev_code' => string|null] — the code is
     * only echoed back in the local environment so developers can test.
     */
    public function request(string $rawPhone, bool $allowRegister = false): array
    {
        $phone = $this->normalize($rawPhone);
        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => ['Enter a valid mobile number.']]);
        }

        $recent = LoginOtp::where('phone', $phone)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))
            ->exists();
        if ($recent) {
            throw ValidationException::withMessages(['phone' => ['Please wait a few seconds before requesting another code.']]);
        }

        $user = $this->findUserByPhone($phone);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        LoginOtp::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        // Deliver with the FULL number (country code included) so WhatsApp/SMS can
        // reach it. Existing users get it via their account; a NEW number gets it
        // only on a registration request (sent from the platform OTP number / SMS).
        $fullDigits = preg_replace('/\D+/', '', $rawPhone) ?: $phone;
        if ($user) {
            $this->sender->send($user, $fullDigits, $code);
        } elseif ($allowRegister) {
            $this->sender->send(null, $fullDigits, $code);
        }

        // In local/testing, echo the code back so developers can complete the flow
        // without a live SMS/WhatsApp channel. (Never in production.)
        return ['dev_code' => app()->environment(['local', 'testing']) ? $code : null];
    }

    /**
     * Verify a code. Returns the matching User on success, or a reason string:
     *   'invalid'    — no live code, too many attempts, or wrong code
     *   'no_account' — the code was right but no user has this number
     */
    public function verify(string $rawPhone, string $code, bool $allowRegister = false): User|string
    {
        $phone = $this->normalize($rawPhone);

        $otp = LoginOtp::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return 'invalid';
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return 'invalid';
        }

        $otp->update(['consumed_at' => now()]);

        $user = $this->findUserByPhone($phone);
        if ($user) {
            return $user;
        }

        // Registration-by-OTP: provision a minimal workspace for this number; the
        // customer completes company name / email etc. later from their profile.
        if ($allowRegister) {
            return $this->registerByPhone($rawPhone);
        }

        return 'no_account';
    }

    /** Create a minimal phone-only workspace (details filled later in profile). */
    private function registerByPhone(string $rawPhone): User
    {
        $full = preg_replace('/\D+/', '', $rawPhone) ?: '';

        return $this->registerTenant->execute(new RegisterData(
            companyName: 'My Workspace',
            name: 'New User',
            // Synthetic unique placeholder — the customer sets a real email later.
            email: 'otp_' . $full . '@users.eydia.in',
            password: Str::random(40),
            phone: '+' . $full,
        ));
    }

    /** Digits only; keeps the trailing 10 (local number) as the match key. */
    private function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /** The active user whose phone ends with these digits. */
    private function findUserByPhone(string $phone): ?User
    {
        if ($phone === '') {
            return null;
        }

        $base = User::withoutGlobalScopes()
            ->whereNotNull('phone')
            ->where('status', '!=', 'disabled');

        // MySQL/MariaDB: strip the common separators with nested REPLACE (works on
        // every version — no REGEXP_REPLACE, which needs MySQL 8+) and compare the
        // last 10 digits. Other drivers (sqlite in tests) match in PHP.
        if (DB::connection()->getDriverName() === 'mysql') {
            $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'+',''),' ',''),'-',''),'(',''),')','')";

            return (clone $base)
                ->whereRaw("RIGHT($stripped, 10) = ?", [$phone])
                ->orderByDesc('last_login_at')
                ->first();
        }

        return (clone $base)
            ->orderByDesc('last_login_at')
            ->get()
            ->first(fn (User $u) => substr(preg_replace('/\D+/', '', (string) $u->phone), -10) === $phone);
    }
}
