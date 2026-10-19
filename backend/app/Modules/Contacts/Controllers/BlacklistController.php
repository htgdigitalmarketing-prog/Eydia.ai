<?php

namespace App\Modules\Contacts\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User black list. Numbers added here are excluded from every outbound send.
 * Tenant-scoped automatically via the BelongsToTenant global scope.
 */
class BlacklistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('contacts.view');

        $query = BlacklistEntry::orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('phone', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        $entries = $query->paginate($request->integer('per_page', 50));

        return $this->ok([
            'entries' => $entries->map(fn (BlacklistEntry $e) => $this->toArray($e))->all(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('contacts.delete');

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $phone = BlacklistEntry::normalize($data['phone']);

        $entry = BlacklistEntry::updateOrCreate(
            ['tenant_id' => $request->user()->tenant_id, 'phone' => $phone],
            ['name' => $data['name'] ?? null, 'reason' => $data['reason'] ?? null, 'source' => 'manual'],
        );

        return $this->ok(['entry' => $this->toArray($entry)], 201);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('contacts.delete');

        $entry = BlacklistEntry::where('uuid', $uuid)->firstOrFail();
        $entry->delete();

        return $this->ok(['message' => 'Number removed from the black list.']);
    }

    private function toArray(BlacklistEntry $e): array
    {
        return [
            'id' => $e->uuid,
            'phone' => $e->phone,
            'name' => $e->name,
            'reason' => $e->reason,
            'source' => $e->source,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
