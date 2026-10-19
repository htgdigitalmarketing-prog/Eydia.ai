<?php

namespace App\Modules\Campaigns\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Contact;
use App\Models\WhatsappPhoneNumber;
use App\Modules\Campaigns\Resources\CampaignResource;
use App\Modules\Campaigns\Services\CampaignSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignSendService $sender) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $query = Campaign::with(['template', 'phoneNumber'])
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%' . $this->escapeLike($search) . '%');
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $campaigns = $query->paginate($request->integer('per_page', 25));

        foreach ($campaigns->items() as $campaign) {
            if ($this->sender->claimIfStalled($campaign)) {
                $this->sender->continueAfterResponse($campaign);
            }
        }

        return $this->ok([
            'campaigns' => CampaignResource::collection($campaigns),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $campaign = Campaign::where('uuid', $uuid)
            ->with(['template', 'phoneNumber'])
            ->firstOrFail();

        if ($this->sender->claimIfStalled($campaign)) {
            $this->sender->continueAfterResponse($campaign);
        }

        $contacts = $campaign->campaignContacts()
            ->with('contact:id,uuid,name,phone')
            ->paginate(25);

        $contactItems = $contacts->getCollection()->map(fn ($cc) => [
            'id' => $cc->id,
            'status' => $cc->status,
            'contact' => $cc->contact ? [
                'id' => $cc->contact->uuid,
                'name' => $cc->contact->name,
                'phone' => $cc->contact->phone,
            ] : null,
        ]);

        return $this->ok([
            'campaign' => new CampaignResource($campaign),
            'campaign_contacts' => $contactItems,
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'total' => $contacts->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template_id' => ['required', 'string', 'exists:templates,uuid'],
            'audience_filter' => ['nullable', 'array'],
            'audience_filter.tags' => ['nullable', 'array'],
            'audience_filter.tags.*' => ['string'],
            'audience_filter.source' => ['nullable', 'string'],
            'variable_mapping' => ['nullable', 'array'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $tenantId = $request->user()->tenant_id;
        app(\App\Modules\Billing\Services\PlanLimitService::class)->assertWithinLimit($tenantId, 'max_campaigns', 'campaigns', 'campaigns');

        $template = \App\Models\Template::where('uuid', $data['template_id'])->first();
        if (! $template) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'template_id' => ['That template could not be found. Sync your templates and try again.'],
            ]);
        }

        // Prefer the default number; fall back to any active number.
        $phone = WhatsappPhoneNumber::where('is_default', true)->first()
            ?? WhatsappPhoneNumber::whereIn('status', ['connected', 'registered'])->first();
        if (! $phone) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'template_id' => ['No active WhatsApp number found. Please connect and register a WhatsApp number on the WhatsApp page before creating a campaign.'],
            ]);
        }

        $status = isset($data['scheduled_at']) ? 'scheduled' : 'draft';

        // Build audience query
        $audienceQuery = Contact::where('is_blocked', false)
            ->where('opted_out', false);

        $audienceFilter = $data['audience_filter'] ?? [];

        if (!empty($audienceFilter['tags'])) {
            $audienceQuery->whereHas('tags', function ($q) use ($audienceFilter) {
                $q->whereIn('tags.name', $audienceFilter['tags']);
            });
        }

        if (!empty($audienceFilter['source'])) {
            $audienceQuery->where('source', $audienceFilter['source']);
        }

        $contacts = $audienceQuery->get();

        $campaign = Campaign::create([
            'tenant_id' => $tenantId,
            'whatsapp_phone_number_id' => $phone->id,
            'template_id' => $template->id,
            'name' => $data['name'],
            'status' => $status,
            'audience_filter' => $audienceFilter ?: null,
            'variable_mapping' => $data['variable_mapping'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'total_recipients' => $contacts->count(),
            'sent_count' => 0,
            'delivered_count' => 0,
            'read_count' => 0,
            'failed_count' => 0,
            'replied_count' => 0,
        ]);

        foreach ($contacts as $contact) {
            CampaignContact::create([
                'tenant_id' => $tenantId,
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        return $this->ok([
            'campaign' => new CampaignResource($campaign->load(['template', 'phoneNumber'])),
        ], 201);
    }

    public function start(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $campaign = Campaign::where('uuid', $uuid)
            ->with(['template', 'phoneNumber'])
            ->firstOrFail();

        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            return $this->fail('Campaign can only be started from draft or scheduled status.', 422);
        }

        $campaign->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->sender->process($campaign, CampaignSendService::INLINE_SECONDS);
        if ($campaign->fresh()?->status === 'running') {
            $this->sender->continueAfterResponse($campaign);
        }

        return $this->ok([
            'campaign' => new CampaignResource($campaign->fresh(['template', 'phoneNumber'])),
        ]);
    }

    public function pause(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $campaign = Campaign::where('uuid', $uuid)->firstOrFail();

        if ($campaign->status !== 'running') {
            return $this->fail('Only running campaigns can be paused.', 422);
        }

        $campaign->update(['status' => 'paused']);

        return $this->ok([
            'campaign' => new CampaignResource($campaign->load(['template', 'phoneNumber'])),
        ]);
    }

    public function cancel(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $campaign = Campaign::where('uuid', $uuid)->firstOrFail();

        $campaign->update(['status' => 'cancelled']);

        return $this->ok([
            'campaign' => new CampaignResource($campaign->load(['template', 'phoneNumber'])),
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $campaign = Campaign::where('uuid', $uuid)->firstOrFail();

        if ($campaign->status !== 'draft') {
            return $this->fail('Only draft campaigns can be deleted.', 422);
        }

        $campaign->delete();

        return $this->ok(['message' => 'Campaign deleted.']);
    }
}
