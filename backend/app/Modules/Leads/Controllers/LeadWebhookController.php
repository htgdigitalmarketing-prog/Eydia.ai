<?php

namespace App\Modules\Leads\Controllers;

use App\Models\Integration;
use App\Modules\Leads\Services\LeadImportService;
use App\Modules\Leads\Services\MetaLeadProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Public webhook endpoints for inbound lead ads — no auth middleware, verified
 * inside the controller. Meta pushes leadgen events (GET verifies, POST
 * delivers); Google Lead Forms POST a key-guarded payload. Each inbound lead is
 * mapped to the owning tenant's pipeline via LeadImportService.
 */
class LeadWebhookController
{
    public function __construct(
        private LeadImportService $importer,
        private MetaLeadProvider $meta,
    ) {
    }

    /** Meta webhook verification handshake (GET). Echoes hub.challenge. */
    public function metaVerify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $this->isValidVerifyToken($token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** Meta leadgen delivery (POST). Fetches each lead and imports it. */
    public function metaReceive(Request $request): JsonResponse
    {
        if (! $this->verifyMetaSignature($request)) {
            Log::warning('Lead webhook: Meta signature verification failed.');

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $body = $request->all();
        if (($body['object'] ?? null) !== 'page') {
            return response()->json(['status' => 'ignored'], 200);
        }

        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'leadgen') {
                    continue;
                }
                $value = $change['value'] ?? [];
                $this->handleLeadgen($value);
            }
        }

        // Always 200 so Meta does not retry a payload we have already accepted.
        return response()->json(['status' => 'received'], 200);
    }

    private function handleLeadgen(array $value): void
    {
        $pageId = $value['page_id'] ?? null;
        $leadgenId = $value['leadgen_id'] ?? null;
        if (! $pageId || ! $leadgenId) {
            return;
        }

        // Find which tenant owns this Page (unauthenticated context → no scope).
        $integration = Integration::withoutGlobalScopes()
            ->where('provider', Integration::META_LEADS)
            ->where('external_account_id', (string) $pageId)
            ->first();

        if (! $integration || ! $integration->access_token) {
            Log::info('Lead webhook: no connected tenant for page ' . $pageId);

            return;
        }

        $lead = $this->meta->fetchLead((string) $leadgenId, $integration->access_token);
        if (! $lead) {
            return;
        }

        $this->importer->importMetaLead($integration->tenant_id, $lead);
        $integration->update(['last_synced_at' => now()]);
    }

    /**
     * Google Lead Form delivery (POST). Google posts a JSON body that includes a
     * shared "google_key" secret configured on the lead form extension. We match
     * it to the tenant whose integration stored that key.
     */
    public function googleReceive(Request $request): JsonResponse
    {
        $payload = $request->all();
        $key = $payload['google_key'] ?? $request->header('X-Google-Key');
        if (! $key) {
            return response()->json(['error' => 'Missing key'], 403);
        }

        // Tenants store their chosen delivery key in the integration meta.
        $integration = Integration::withoutGlobalScopes()
            ->where('provider', Integration::GOOGLE_LEADS)
            ->get()
            ->first(fn (Integration $i) => hash_equals((string) ($i->meta['google_key'] ?? ''), (string) $key));

        if (! $integration) {
            Log::warning('Lead webhook: no tenant matched the Google key.');

            return response()->json(['error' => 'Invalid key'], 403);
        }

        $this->importer->importGoogleLead($integration->tenant_id, $payload);
        $integration->update(['last_synced_at' => now()]);

        return response()->json(['status' => 'received'], 200);
    }

    private function isValidVerifyToken(?string $token): bool
    {
        if (empty($token)) {
            return false;
        }
        if (($primary = config('services.meta.verify_token')) && $token === $primary) {
            return true;
        }
        foreach (config('services.meta.verify_tokens', []) as $valid) {
            if ($token === $valid) {
                return true;
            }
        }

        return false;
    }

    private function verifyMetaSignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        if (empty($signature)) {
            return false;
        }

        $secrets = array_filter(array_merge(
            [config('services.meta.app_secret')],
            config('services.meta.app_secrets', []),
        ));
        $content = $request->getContent();
        foreach ($secrets as $secret) {
            if (hash_equals('sha256=' . hash_hmac('sha256', $content, $secret), $signature)) {
                return true;
            }
        }

        return false;
    }
}
