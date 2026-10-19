<?php

namespace App\Modules\Contacts\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Modules\Contacts\Resources\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('contacts.view');

        $query = Contact::with(['tags', 'assignedAgent'])
            ->orderByDesc('last_interaction_at')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $safe = '%' . $this->escapeLike($search) . '%';
            $query->where(function ($q) use ($safe) {
                $q->where('name', 'like', $safe)
                  ->orWhere('phone', 'like', $safe)
                  ->orWhere('wa_id', 'like', $safe)
                  ->orWhere('email', 'like', $safe)
                  ->orWhere('tag_list', 'like', $safe);
            });
        }

        if ($tag = $request->query('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.uuid', $tag));
        }

        if ($request->query('hot') === '1') {
            // Hot List = contacts the AI tagged "Hot" in contacts.tag_list
            $query->whereRaw(
                "CONCAT(',', REPLACE(REPLACE(tag_list, ', ', ','), ' ', ''), ',') LIKE ?",
                ['%,' . Contact::HOT_TAG . ',%']
            );
            // First priority first: highest AI score, then most recent chat.
            $query->reorder()
                ->orderByRaw(Contact::hotPrioritySql('contacts') . ' DESC')
                ->orderByDesc('last_interaction_at');
        }

        if ($request->query('blocked') === '1') {
            $query->where('is_blocked', true);
        }

        if ($request->query('opted_out') === '1') {
            $query->where('opted_out', true);
        }

        $contacts = $query->paginate($request->integer('per_page', 25));

        return $this->ok([
            'contacts' => ContactResource::collection($contacts),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'total' => $contacts->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('contacts.view');

        $contact = Contact::where('uuid', $uuid)
            ->with(['tags', 'assignedAgent', 'conversations'])
            ->firstOrFail();

        return $this->ok(['contact' => new ContactResource($contact)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('contacts.create');

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:4'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'tag_list' => ['nullable', 'string', 'max:500'],   // e.g. "VIP" or "VIP, Delhi"
        ]);

        $tenantId = $request->user()->tenant_id;
        app(\App\Modules\Billing\Services\PlanLimitService::class)->assertWithinLimit($tenantId, 'max_contacts', 'contacts', 'contacts');
        $waId = preg_replace('/\D/', '', $data['phone']);

        $contact = Contact::create([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'phone' => $data['phone'],
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'company' => $data['company'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'language' => $data['language'] ?? null,
            'country' => $data['country'] ?? null,
            'tag_list' => $this->tagText($data),
        ]);

        return $this->ok(['contact' => new ContactResource($contact->load('assignedAgent'))], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('contacts.update');

        $contact = Contact::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:4'],
            'is_blocked' => ['nullable', 'boolean'],
            'opted_out' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'tag_list' => ['nullable', 'string', 'max:500'],
        ]);

        $contact->update(collect($data)->except(['tags', 'tag_list'])->filter(fn ($v) => $v !== null)->all());

        // tag_list is saved directly in the contacts table (empty text clears it)
        if (array_key_exists('tags', $data) || array_key_exists('tag_list', $data)) {
            $before = collect($contact->tagNames())->map(fn ($t) => mb_strtolower($t));
            $contact->update(['tag_list' => $this->tagText($data)]);
            $this->fireTagAutomations($contact->fresh(), $before);
        }

        return $this->ok(['contact' => new ContactResource($contact->fresh(['assignedAgent']))]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('contacts.delete');

        $contact = Contact::where('uuid', $uuid)->firstOrFail();
        $contact->delete();

        return $this->ok(['message' => 'Contact deleted.']);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorize('contacts.import');

        $data = $request->validate([
            'contacts' => ['required', 'array', 'min:1', 'max:5000'],
            'contacts.*.phone' => ['required', 'string', 'max:20'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.company' => ['nullable', 'string', 'max:255'],
            'contacts.*.tags' => ['nullable', 'array'],
            'contacts.*.tags.*' => ['string'],
            'contacts.*.tag_list' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = $request->user()->tenant_id;
        app(\App\Modules\Billing\Services\PlanLimitService::class)->assertWithinLimit($tenantId, 'max_contacts', 'contacts', 'contacts');
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($data['contacts'] as $row) {
            $waId = preg_replace('/\D/', '', $row['phone']);
            if (strlen($waId) < 10) {
                $skipped++;
                continue;
            }

            $contact = Contact::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('wa_id', $waId)
                ->first();
                

            if ($contact) {
                if ($contact->trashed()) {
                    $contact->restore();
                }
                $contact->update(array_filter([
                    'name' => $row['name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'company' => $row['company'] ?? null,
                ], fn ($v) => $v !== null));
                $updated++;
            } else {
                $contact = Contact::create([
                    'tenant_id' => $tenantId,
                    'wa_id' => $waId,
                    'phone' => $row['phone'],
                    'name' => $row['name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'company' => $row['company'] ?? null,
                    'source' => 'import',
                ]);
                $created++;
            }

            // save the tag text straight into contacts.tag_list
            $tagText = $this->tagText($row);
            if ($tagText !== null) {
                $contact->update(['tag_list' => $tagText]);
            }
        }

        return $this->ok([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($data['contacts']),
        ]);
    }

    /**
     * Tag text for contacts.tag_list from `tag_list` (text) or `tags` (array).
     * Returns null when nothing was sent / it is empty.
     * Example: "vip | Delhi;lead" => "vip, Delhi, lead"
     */
    private function tagText(array $data): ?string
    {
        $raw = $data['tag_list'] ?? ($data['tags'] ?? null);
        if (is_array($raw)) {
            $raw = implode(',', $raw);
        }
        if ($raw === null) {
            return null;
        }

        $text = collect(preg_split('/[,;|]/', (string) $raw))
            ->map(fn ($n) => trim($n))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->implode(', ');

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    /**
     * Start tag_added / tag_removed workflows for the tags that actually changed.
     * Best-effort — a dispatch failure must not break saving the contact.
     *
     * @param  \Illuminate\Support\Collection<int,string>  $before  lower-cased tags before the edit
     */
    private function fireTagAutomations(Contact $contact, $before): void
    {
        try {
            $after = collect($contact->tagNames())->map(fn ($t) => mb_strtolower($t));
            $added = $after->diff($before);
            $removed = $before->diff($after);

            if ($added->isEmpty() && $removed->isEmpty()) {
                return;
            }

            $dispatcher = app(\App\Modules\Automation\Services\WorkflowDispatcher::class);
            $conversation = $contact->conversations()->latest('id')->first();

            foreach ($added as $tag) {
                $dispatcher->fire($contact->tenant_id, 'tag_added', $contact, $conversation, ['tag' => $tag]);
            }
            foreach ($removed as $tag) {
                $dispatcher->fire($contact->tenant_id, 'tag_removed', $contact, $conversation, ['tag' => $tag]);
            }
        } catch (\Throwable) {
            // Swallow — automations are best-effort here.
        }
    }
}