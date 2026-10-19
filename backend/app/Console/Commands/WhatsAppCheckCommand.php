<?php

namespace App\Console\Commands;

use App\Modules\WhatsApp\Support\Masker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Verifies the Meta System User token in .env against the live Graph API and,
 * optionally, lists the phone numbers under a WABA so you can grab the
 * phone_number_id needed to connect a number.
 *
 *   php artisan whatsapp:check
 *   php artisan whatsapp:check --waba=YOUR_WABA_ID
 *   php artisan whatsapp:check --phone=YOUR_PHONE_NUMBER_ID
 *
 * Never prints the full token.
 */
class WhatsAppCheckCommand extends Command
{
    protected $signature = 'whatsapp:check {--waba= : List phone numbers under this WABA ID} {--phone= : Fetch details for this phone_number_id}';

    protected $description = 'Verify the Meta System User token and inspect WABA phone numbers';

    public function handle(): int
    {
        $token = (string) config('services.meta.system_user_token');
        if ($token === '') {
            $this->error('META_SYSTEM_USER_TOKEN is empty. Set it in .env, then run: php artisan config:clear');
            return self::FAILURE;
        }

        $version = config('services.meta.api_version', 'v23.0');
        $base = rtrim((string) config('services.meta.graph_base', 'https://graph.facebook.com'), '/') . "/{$version}";
        $appId = config('services.meta.app_id');
        $appSecret = config('services.meta.app_secret');

        $this->line('Graph base : ' . $base);
        $this->line('Token      : ' . Masker::token($token));
        $this->newLine();

        // 1) Validate the token via debug_token (needs app id + secret).
        if ($appId && $appSecret) {
            $dbg = Http::acceptJson()->get("{$base}/debug_token", [
                'input_token' => $token,
                'access_token' => "{$appId}|{$appSecret}",
            ]);
            $d = $dbg->json('data');

            if ($dbg->successful() && is_array($d)) {
                $valid = ($d['is_valid'] ?? false);
                $this->line('Token valid: ' . ($valid ? '<info>YES</info>' : '<error>NO</error>'));
                $this->line('App ID     : ' . ($d['app_id'] ?? '?'));
                $this->line('Type       : ' . ($d['type'] ?? '?'));
                $exp = (int) ($d['expires_at'] ?? 0);
                $this->line('Expires    : ' . ($exp === 0 ? 'never' : date('Y-m-d H:i', $exp)));
                $this->line('Scopes     : ' . implode(', ', $d['scopes'] ?? []));

                if (! $valid) {
                    $this->error('Token is not valid — regenerate it in Business Settings → System Users.');
                    return self::FAILURE;
                }
            } else {
                $this->warn('debug_token failed: ' . json_encode($dbg->json('error') ?? $dbg->body()));
            }
        } else {
            $this->warn('META_APP_SECRET not set — skipping debug_token; trying /me instead.');
            $me = Http::withToken($token)->acceptJson()->get("{$base}/me", ['fields' => 'id,name']);
            $this->line('/me: ' . json_encode($me->json()));
            if ($me->failed()) {
                $this->error('Token appears invalid.');
                return self::FAILURE;
            }
        }

        // 2) List phone numbers under a WABA (great for grabbing phone_number_id).
        if ($waba = $this->option('waba')) {
            $this->newLine();
            $this->info("Phone numbers under WABA {$waba}:");
            $res = Http::withToken($token)->acceptJson()->get("{$base}/{$waba}/phone_numbers", [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status',
            ]);

            if ($res->successful()) {
                $rows = $res->json('data', []);
                if ($rows === []) {
                    $this->warn('  No phone numbers found on this WABA.');
                }
                foreach ($rows as $n) {
                    $this->line(sprintf(
                        '  phone_number_id=%s | %s | %s | quality=%s | verify=%s',
                        $n['id'] ?? '?',
                        $n['display_phone_number'] ?? '?',
                        $n['verified_name'] ?? '-',
                        $n['quality_rating'] ?? '-',
                        $n['code_verification_status'] ?? '-',
                    ));
                }
            } else {
                $this->error('  Error: ' . json_encode($res->json('error') ?? $res->body()));
            }
        }

        // 3) Inspect a single phone number.
        if ($phone = $this->option('phone')) {
            $this->newLine();
            $res = Http::withToken($token)->acceptJson()->get("{$base}/{$phone}", [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status',
            ]);
            $this->info("Phone {$phone}:");
            $this->line('  ' . json_encode($res->json()));
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
