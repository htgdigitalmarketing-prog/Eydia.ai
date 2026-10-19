<?php

namespace App\Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Lead;
use App\Modules\Leads\Services\LeadImportService;
use App\Modules\Leads\Services\MetaLeadProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Lead Management. Leads flow in from Google, Meta (Facebook/Instagram),
 * WhatsApp, website forms, or manual entry, and move through a Kanban pipeline.
 * Tenant scoping is automatic via the Lead model's BelongsToTenant scope.
 */
class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Lead::query()->with('agent:id,uuid,name')->latest();

        if ($source = $request->query('source')) {
            $query->where('source', strtoupper($source));
        }
        if ($stage = $request->query('stage')) {
            $query->where('stage', strtoupper($stage));
        }
        if ($search = $request->query('search')) {
            $like = '%' . $this->escapeLike($search) . '%';
            $query->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('company', 'like', $like));
        }

        $leads = $query->get();

        // Pipeline + source breakdown for the dashboard header cards.
        $all = Lead::query()->get(['source', 'stage', 'value']);
        $byStage = [];
        foreach (Lead::STAGES as $s) {
            $byStage[$s] = $all->where('stage', $s)->count();
        }
        $bySource = [];
        foreach (Lead::SOURCES as $s) {
            $bySource[$s] = $all->where('source', $s)->count();
        }

        return $this->ok([
            'leads' => $leads->map(fn (Lead $l) => $this->leadArray($l))->all(),
            'stages' => Lead::STAGES,
            'sources' => Lead::SOURCES,
            'stats' => [
                'total' => $all->count(),
                'by_stage' => $byStage,
                'by_source' => $bySource,
                'converted' => $all->where('stage', 'CONVERTED')->count(),
                'pipeline_value' => (float) $all->whereNotIn('stage', ['CONVERTED', 'LOST'])->sum('value'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::in(Lead::SOURCES)],
            'stage' => ['nullable', Rule::in(Lead::STAGES)],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['stage'] = $data['stage'] ?? 'NEW';

        $lead = Lead::create($data);

        return $this->ok(['lead' => $this->leadArray($lead)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'stage' => ['sometimes', Rule::in(Lead::STAGES)],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $lead->update($data);

        return $this->ok(['lead' => $this->leadArray($lead->fresh('agent'))]);
    }

    /** Move a lead to another pipeline stage (Kanban drag/drop). */
    public function move(Request $request, string $uuid): JsonResponse
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'stage' => ['required', Rule::in(Lead::STAGES)],
        ]);

        $lead->update([
            'stage' => $data['stage'],
            'last_contacted_at' => in_array($data['stage'], ['CONTACTED', 'FOLLOW_UP', 'APPOINTMENT'], true)
                ? now() : $lead->last_contacted_at,
        ]);

        return $this->ok(['lead' => $this->leadArray($lead->fresh('agent'))]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $lead->delete();

        return $this->ok(['message' => 'Lead deleted.']);
    }

    /**
     * Pull new leads from connected Google / Meta sources.
     *
     * Meta leads arrive in real time via the leadgen webhook; this button
     * backfills recent leads through the Graph API in case a delivery was
     * missed or the Page was connected after leads came in. Google Lead Forms
     * only push (no pull API without the Ads API), so GOOGLE reports the
     * webhook endpoint instead of fetching.
     */
    public function sync(Request $request, MetaLeadProvider $meta, LeadImportService $importer): JsonResponse
    {
        $source = strtoupper((string) $request->input('source', 'ALL'));
        $imported = 0;
        $messages = [];

        if ($source === 'META' || $source === 'ALL') {
            $integration = Integration::where('provider', Integration::META_LEADS)->first();
            if ($integration && $integration->isConnected()) {
                $leads = $meta->fetchRecentLeads($integration->external_account_id, $integration->access_token);
                foreach ($leads as $lead) {
                    if ($importer->importMetaLead($integration->tenant_id, $lead)['created']) {
                        $imported++;
                    }
                }
                $integration->update(['last_synced_at' => now()]);
                $messages[] = $meta->getLastError() ?? "Meta: imported {$imported} new lead(s).";
            } else {
                $messages[] = 'Meta: not connected. Connect Meta Lead Ads under Settings.';
            }
        }

        if ($source === 'GOOGLE' || $source === 'ALL') {
            $integration = Integration::where('provider', Integration::GOOGLE_LEADS)->first();
            $messages[] = $integration && $integration->isConnected()
                ? 'Google: leads arrive automatically via the lead-form webhook.'
                : 'Google: not connected. Set up the lead-form webhook under Settings.';
        }

        return $this->ok([
            'synced' => $imported,
            'source' => $source,
            'message' => implode(' ', $messages),
        ]);
    }

    /**
     * Google Lead Form webhook details for this tenant: generates (once) a
     * delivery key and returns the webhook URL + key to paste into the Google
     * Ads lead-form extension. The key authenticates inbound Google posts.
     */
    public function googleConnection(Request $request): JsonResponse
    {
        $integration = Integration::firstOrCreate(
            ['tenant_id' => $request->user()->tenant_id, 'provider' => Integration::GOOGLE_LEADS],
            ['status' => 'connected', 'meta' => ['google_key' => Str::random(40)]],
        );

        // Backfill a key for a row created before this field existed.
        if (empty($integration->meta['google_key'])) {
            $integration->update(['meta' => array_merge($integration->meta ?? [], ['google_key' => Str::random(40)])]);
        }

        return $this->ok([
            'connected' => true,
            'webhook_url' => url('/api/webhooks/leads/google'),
            'google_key' => $integration->meta['google_key'],
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
        ]);
    }

    public function googleDisconnect(Request $request): JsonResponse
    {
        Integration::where('provider', Integration::GOOGLE_LEADS)->delete();

        return $this->ok(['message' => 'Google Lead Form import disconnected.']);
    }

    private function leadArray(Lead $l): array
    {
        return [
            'id' => $l->uuid,
            'name' => $l->name,
            'phone' => $l->phone,
            'email' => $l->email,
            'company' => $l->company,
            'source' => $l->source,
            'stage' => $l->stage,
            'score' => $l->score,
            'value' => $l->value !== null ? (float) $l->value : null,
            'notes' => $l->notes,
            'agent' => $l->agent ? ['id' => $l->agent->uuid, 'name' => $l->agent->name] : null,
            'last_contacted_at' => $l->last_contacted_at?->toIso8601String(),
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
