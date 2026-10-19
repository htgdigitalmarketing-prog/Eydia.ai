<?php

namespace App\Modules\Leads\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Meta Graph API for Lead Ads. Exchanges OAuth codes for
 * tokens, lists the Pages a user manages, subscribes a Page to the leadgen
 * webhook, and fetches lead field data. Uses the META_* app credentials from
 * config/services.php and each tenant's stored Page access token.
 */
class MetaLeadProvider
{
    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public static function configured(): bool
    {
        return (bool) config('services.meta.app_id') && (bool) config('services.meta.app_secret');
    }

    private function graph(): string
    {
        return rtrim((string) config('services.meta.graph_base'), '/') . '/' . config('services.meta.api_version');
    }

    private function client()
    {
        return Http::acceptJson()->timeout((int) config('services.meta.timeout', 30));
    }

    /** Exchange an auth code for a short-lived user token, then upgrade to long-lived. */
    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        $this->lastError = null;

        $res = $this->client()->get($this->graph() . '/oauth/access_token', [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        if (! $res->successful()) {
            $this->lastError = 'Could not get a token from Meta: ' . $res->body();

            return null;
        }
        $shortToken = $res->json('access_token');

        $long = $this->client()->get($this->graph() . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $shortToken,
        ]);

        return [
            'user_token' => $long->successful() ? $long->json('access_token') : $shortToken,
            'expires_in' => $long->successful() ? $long->json('expires_in') : null,
        ];
    }

    /** Pages this user manages, each with its own page-scoped token. */
    public function listPages(string $userToken): array
    {
        $this->lastError = null;
        $res = $this->client()->get($this->graph() . '/me/accounts', [
            'access_token' => $userToken,
            'fields' => 'id,name,access_token,instagram_business_account',
        ]);

        if (! $res->successful()) {
            $this->lastError = 'Could not list Facebook Pages: ' . $res->body();

            return [];
        }

        return $res->json('data', []);
    }

    /** Subscribe a Page to the app's leadgen webhook so new leads are pushed to us. */
    public function subscribePage(string $pageId, string $pageToken): bool
    {
        $res = $this->client()->asForm()->post($this->graph() . "/{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'leadgen',
            'access_token' => $pageToken,
        ]);

        if (! $res->successful()) {
            Log::warning('Meta subscribePage failed: ' . $res->body());
            $this->lastError = 'Could not subscribe the Page to lead notifications: ' . $res->body();

            return false;
        }

        return true;
    }

    /** Fetch a single lead's field data by its leadgen id (from the webhook). */
    public function fetchLead(string $leadgenId, string $pageToken): ?array
    {
        $res = $this->client()->get($this->graph() . "/{$leadgenId}", [
            'access_token' => $pageToken,
            'fields' => 'id,created_time,field_data,form_id,ad_id,campaign_id,platform',
        ]);

        if (! $res->successful()) {
            Log::warning('Meta fetchLead failed: ' . $res->body());
            $this->lastError = 'Could not fetch the lead from Meta: ' . $res->body();

            return null;
        }

        return $res->json();
    }

    /**
     * Pull recent leads for a Page across its lead-gen forms. Used by the manual
     * "Sync" button to backfill leads that arrived before the Page was connected
     * (or if a webhook delivery was missed).
     */
    public function fetchRecentLeads(string $pageId, string $pageToken, int $limit = 50): array
    {
        $this->lastError = null;

        $forms = $this->client()->get($this->graph() . "/{$pageId}/leadgen_forms", [
            'access_token' => $pageToken,
            'fields' => 'id,name',
            'limit' => 100,
        ]);
        if (! $forms->successful()) {
            $this->lastError = 'Could not list lead forms: ' . $forms->body();

            return [];
        }

        $leads = [];
        foreach ($forms->json('data', []) as $form) {
            $res = $this->client()->get($this->graph() . "/{$form['id']}/leads", [
                'access_token' => $pageToken,
                'fields' => 'id,created_time,field_data,form_id,ad_id,campaign_id,platform',
                'limit' => $limit,
            ]);
            if ($res->successful()) {
                foreach ($res->json('data', []) as $lead) {
                    $lead['form_name'] = $form['name'] ?? null;
                    $leads[] = $lead;
                }
            }
        }

        return $leads;
    }
}
