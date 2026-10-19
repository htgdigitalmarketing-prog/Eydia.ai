<?php

namespace App\Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Modules\Leads\Services\MetaLeadProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Meta (Facebook Login for Business) "connect" flow for Lead Ads, per tenant.
 *
 *   1. GET  /leads/meta/connect (auth) -> consent URL for the SPA
 *   2. GET  /auth/meta-leads/callback  -> exchange code, store the Page token,
 *                                         subscribe the Page to the leadgen
 *                                         webhook, bounce to the frontend
 *   3. GET/DELETE /leads/meta/connection (auth) -> status / disconnect
 *
 * MVP stores the FIRST Page the user manages (page-scoped token encrypted in
 * access_token, long-lived user token in refresh_token). Needs META_APP_ID /
 * META_APP_SECRET; until set, connect reports "not configured".
 */
class MetaLeadsController extends Controller
{
    public function __construct(private MetaLeadProvider $provider)
    {
    }

    public function connect(Request $request): JsonResponse
    {
        if (! MetaLeadProvider::configured()) {
            return $this->fail('Meta is not configured yet. Add META_APP_ID and META_APP_SECRET to the backend .env.', [], 422);
        }

        $state = Str::random(40);
        Cache::put('meta_leads_oauth:' . $state, [
            'tenant_id' => $request->user()->tenant_id,
            'user_id' => $request->user()->id,
        ], now()->addMinutes(15));

        $params = http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => config('services.meta_leads.redirect'),
            'response_type' => 'code',
            'scope' => implode(',', config('services.meta_leads.scopes')),
            'state' => $state,
        ]);

        $version = config('services.meta.api_version');

        return $this->ok(['url' => "https://www.facebook.com/{$version}/dialog/oauth?" . $params]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $return = $this->frontend(config('services.meta_leads.return_path', '/leads'));

        if ($request->filled('error')) {
            return redirect($return . '?meta_connect=cancelled');
        }

        $state = $request->query('state');
        $stored = $state ? Cache::pull('meta_leads_oauth:' . $state) : null;
        if (! $stored || empty($stored['tenant_id'])) {
            return redirect($return . '?meta_connect=invalid_state');
        }

        $tokens = $this->provider->exchangeCode((string) $request->query('code'), config('services.meta_leads.redirect'));
        if (! $tokens) {
            return redirect($return . '?meta_connect=token_failed');
        }

        $pages = $this->provider->listPages($tokens['user_token']);
        if (empty($pages)) {
            return redirect($return . '?meta_connect=no_pages');
        }

        $page = $pages[0]; // MVP: first Page returned.

        // Subscribe the Page so new leads are pushed to our webhook in real time.
        $this->provider->subscribePage($page['id'], $page['access_token']);

        Integration::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $stored['tenant_id'], 'provider' => Integration::META_LEADS],
            [
                'status' => 'connected',
                'access_token' => $page['access_token'],  // page-scoped token
                'refresh_token' => $tokens['user_token'],  // long-lived user token
                'expires_at' => $tokens['expires_in'] ? now()->addSeconds($tokens['expires_in']) : null,
                'external_account_id' => $page['id'],
                'external_account_name' => $page['name'] ?? null,
                'meta' => [
                    'page_id' => $page['id'],
                    'page_name' => $page['name'] ?? null,
                    'ig_user_id' => $page['instagram_business_account']['id'] ?? null,
                    // Pages (names only, no tokens) for display / future multi-page support.
                    'pages' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'] ?? null], $pages),
                ],
            ]
        );

        return redirect($return . '?meta_connect=success');
    }

    public function status(Request $request): JsonResponse
    {
        $integration = Integration::where('provider', Integration::META_LEADS)->first();

        return $this->ok([
            'configured' => MetaLeadProvider::configured(),
            'connected' => (bool) $integration?->isConnected(),
            'status' => $integration?->status,
            'page' => $integration?->external_account_name,
            'last_synced_at' => $integration?->last_synced_at?->toIso8601String(),
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        Integration::where('provider', Integration::META_LEADS)->delete();

        return $this->ok(['message' => 'Meta Lead Ads disconnected.']);
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('services.google.frontend_url'), '/') . '/' . ltrim($path, '/');
    }
}
