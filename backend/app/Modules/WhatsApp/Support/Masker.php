<?php

namespace App\Modules\WhatsApp\Support;

/**
 * Redacts secrets and PII before anything reaches api_logs / structured logs.
 * We NEVER persist a full access token or a full phone number.
 */
class Masker
{
    /** Keys whose values are secrets — replaced with a short fingerprint. */
    private const SECRET_KEYS = ['access_token', 'token', 'authorization', 'pin', 'password', 'secret', 'app_secret'];

    /** Keys whose values are phone numbers / contact ids — kept to last 4 digits. */
    private const PHONE_KEYS = ['to', 'from', 'phone', 'display_phone_number', 'wa_id', 'recipient', 'phone_number'];

    public static function token(?string $value): string
    {
        $value = (string) $value;
        return $value === '' ? '' : str_repeat('*', 6) . substr($value, -4);
    }

    public static function phone(?string $value): string
    {
        $value = (string) $value;
        return strlen($value) <= 4 ? '****' : str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4);
    }

    /** Recursively mask a request/response payload for logging. */
    public static function payload(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            $lower = is_string($key) ? strtolower($key) : $key;

            if (is_array($value)) {
                $out[$key] = self::payload($value);
            } elseif (in_array($lower, self::SECRET_KEYS, true)) {
                $out[$key] = self::token(is_scalar($value) ? (string) $value : '');
            } elseif (in_array($lower, self::PHONE_KEYS, true) && is_scalar($value)) {
                $out[$key] = self::phone((string) $value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
