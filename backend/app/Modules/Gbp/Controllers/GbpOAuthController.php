<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Modules\Gbp\Services\GoogleGbpProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Business Profile "connect account" OAuth 2.0 flow, per tenant.
 *
 *   1. GET  /gbp/connect  (auth)   -> returns the Google consent URL for the SPA
 *   2. GET  /auth/gbp/callback     -> Google redirects back here with a code;
 *                                     we exchange it for tokens and store an
 *                                     Integration(provider=GOOGLE_GBP) for the
 *                                     tenant, then bounce to the frontend.
 *   3. GET  /gbp/connection (auth)  -> connection status for the Settings UI
 *   4. DELETE /gbp/connection (auth)-> disconnect (drop stored tokens)
 *
 * Needs GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET. Until those are set the connect
 * endpoint reports "not configured" and the dashboard keeps serving cached rows.
 */
class GbpOAuthController extends Controller
{
    /** Step 1 — hand the SPA a URL to send the browser to Google's consent screen. */
    public function connect(Request $request): JsonResponse
    {
        if (! GoogleGbpProvider::configured()) {
            return $this->fail('Google is not configured yet. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to the backend .env.', [], 422);
        }

        // The state value is the random secret Google echoes back; it also carries
        // which tenant is connecting. Stored in the cache (not the session) because
        // the session cookie is lost across the Google redirect in an API/SPA setup.
        $state = Str::random(40);
        Cache::put('gbp_oauth:' . $state, [
            'tenant_id' => $request->user()->tenant_id,
            'user_id' => $request->user()->id,
        ], now()->addMinutes(15));

        $params = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.gbp.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google.gbp.scopes')),
            'access_type' => 'offline', // so Google returns a refresh_token
            'prompt' => 'consent',      // force a refresh_token on every connect
            'state' => $state,
        ]);

        return $this->ok(['url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $params]);
    }

    /** Step 2 — Google redirects here (public, browser). Exchange code, store tokens. */
    public function callback(Request $request): RedirectResponse
    {
        $return = $this->frontend(config('services.google.gbp.return_path', '/gbp'));

        if ($request->filled('error')) {
            return redirect($return . '?gbp_connect=cancelled');
        }

        $state = $request->query('state');
        $stored = $state ? Cache::pull('gbp_oauth:' . $state) : null;
        if (! $stored || empty($stored['tenant_id'])) {
            return redirect($return . '?gbp_connect=invalid_state');
        }

        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->query('code'),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.gbp.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $res->successful()) {
            return redirect($return . '?gbp_connect=token_failed');
        }

        $tokens = $res->json();

        // Public (unauthenticated) context: the tenant scope does not constrain
        // here, so scope the upsert explicitly by the tenant_id from the state.
        Integration::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $stored['tenant_id'], 'provider' => Integration::GOOGLE_GBP],
            [
                'status' => 'connected',
                'access_token' => $tokens['access_token'] ?? null,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_at' => isset($tokens['expires_in']) ? now()->addSeconds($tokens['expires_in']) : null,
                'meta' => ['scope' => $tokens['scope'] ?? null],
            ]
        );

        return redirect($return . '?gbp_connect=success');
    }

    /** Connection status for the Settings UI. */
    public function status(Request $request): JsonResponse
    {
        $integration = Integration::where('provider', Integration::GOOGLE_GBP)->first();

        return $this->ok([
            'configured' => GoogleGbpProvider::configured(),
            'connected' => (bool) $integration?->isConnected(),
            'status' => $integration?->status,
            'account' => $integration?->external_account_name,
            'last_synced_at' => $integration?->last_synced_at?->toIso8601String(),
        ]);
    }

    /** Disconnect — remove stored tokens for this tenant. */
    public function disconnect(Request $request): JsonResponse
    {
        Integration::where('provider', Integration::GOOGLE_GBP)->delete();

        return $this->ok(['message' => 'Google Business Profile disconnected.']);
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('services.google.frontend_url'), '/') . '/' . ltrim($path, '/');
    }
}
