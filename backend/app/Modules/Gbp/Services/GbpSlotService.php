<?php

namespace App\Modules\Gbp\Services;

use App\Models\GbpLocation;
use App\Models\PlanLocationSlot;
use App\Modules\Billing\Services\PlanLimitService;

/**
 * Server-side authority for plan-based GBP location slots:
 *   • connect limit   — how many locations a plan may connect (plan.location_limit)
 *   • permanent slots  — a slot is bound to one location forever (no swapping)
 *   • switch set       — only connected locations are selectable in the dashboard
 *
 * "Connected" is bridged to GbpLocation.is_hidden so the existing dashboard
 * (which drives everything off visible() locations) keeps working: a connected
 * location is visible, everything else is hidden.
 */
class GbpSlotService
{
    /** Thrown to the controller when the plan's connect limit is reached. */
    public const LIMIT_MESSAGE = 'Location limit reached — upgrade your plan to connect more locations.';

    public function __construct(private readonly PlanLimitService $planLimits) {}

    /**
     * How many locations this tenant may connect. Free / no plan → 1. A plan uses
     * its `location_limit` (falling back to the legacy `max_locations`). null =
     * unlimited.
     */
    public function limit(int $tenantId): ?int
    {
        $plan = $this->planLimits->planFor($tenantId);
        if (! $plan) {
            return 1; // free / no plan → a single location; buy a plan for more
        }
        $v = $plan->limit('location_limit', $plan->limit('max_locations'));
        if ($v === null || $v === '' || (int) $v < 0) {
            return null; // unlimited
        }

        return (int) $v;
    }

    /** The permanent identity key for a location's slot (stable across re-syncs). */
    public function keyFor(GbpLocation $loc): string
    {
        return $loc->google_location_id ?: ('local:' . $loc->uuid);
    }

    /** @return \Illuminate\Support\Collection<int,PlanLocationSlot> */
    public function slots(int $tenantId)
    {
        return PlanLocationSlot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('slot_no')
            ->get();
    }

    /** Total slots taken (connected + reserved disconnected) — both count against the limit. */
    public function usedCount(int $tenantId): int
    {
        return PlanLocationSlot::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
    }

    public function connectedCount(int $tenantId): int
    {
        return PlanLocationSlot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', PlanLocationSlot::STATUS_CONNECTED)
            ->count();
    }

    private function slotFor(int $tenantId, string $key): ?PlanLocationSlot
    {
        return PlanLocationSlot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('location_key', $key)
            ->first();
    }

    /**
     * Connect (activate) a location into a slot. Enforces the plan limit, slot
     * ownership and the no-swap rule server-side.
     *
     * @return array{ok:bool, code:?string, message:?string, slot:?PlanLocationSlot}
     */
    public function connect(GbpLocation $loc, ?int $userId): array
    {
        $tenantId = $loc->tenant_id;
        $key = $this->keyFor($loc);
        $existing = $this->slotFor($tenantId, $key);

        if ($existing) {
            // This location already owns a slot — reconnecting its OWN reserved
            // slot is always allowed (it never counted as free capacity).
            if (! $existing->isConnected()) {
                $existing->update([
                    'status' => PlanLocationSlot::STATUS_CONNECTED,
                    'gbp_location_id' => $loc->id,
                    'location_name' => $loc->name,
                    'user_id' => $userId ?? $existing->user_id,
                    'plan_id' => $this->planLimits->planFor($tenantId)?->id ?? $existing->plan_id,
                ]);
            }
            $this->setVisible($loc, true);

            return ['ok' => true, 'code' => null, 'message' => null, 'slot' => $existing->fresh()];
        }

        // A brand-new location needs free capacity. Disconnected slots are
        // reserved for their original location, so they still occupy capacity.
        $limit = $this->limit($tenantId);
        if ($limit !== null && $this->usedCount($tenantId) >= $limit) {
            return ['ok' => false, 'code' => 'limit_reached', 'message' => self::LIMIT_MESSAGE, 'slot' => null];
        }

        $nextNo = (int) (PlanLocationSlot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->max('slot_no') ?? 0) + 1;

        $slot = new PlanLocationSlot([
            'user_id' => $userId,
            'plan_id' => $this->planLimits->planFor($tenantId)?->id,
            'slot_no' => $nextNo,
            'gbp_location_id' => $loc->id,
            'location_key' => $key,
            'location_name' => $loc->name,
            'status' => PlanLocationSlot::STATUS_CONNECTED,
        ]);
        $slot->tenant_id = $tenantId; // explicit: sync/queue context has no auth user
        $slot->save();

        $this->setVisible($loc, true);

        return ['ok' => true, 'code' => null, 'message' => null, 'slot' => $slot];
    }

    /**
     * Disconnect a location. Its slot is KEPT and reserved for the same location
     * to reconnect later — it can never be reassigned to a different location.
     */
    public function disconnect(GbpLocation $loc): array
    {
        $slot = $this->slotFor($loc->tenant_id, $this->keyFor($loc));
        if ($slot && $slot->isConnected()) {
            $slot->update(['status' => PlanLocationSlot::STATUS_DISCONNECTED]);
        }
        $this->setVisible($loc, false);

        return ['ok' => true, 'slot' => $slot?->fresh()];
    }

    /** Is this location currently connected (has a connected slot)? */
    public function isConnected(GbpLocation $loc): bool
    {
        $slot = $this->slotFor($loc->tenant_id, $this->keyFor($loc));

        return $slot !== null && $slot->isConnected();
    }

    /**
     * Decoration map for the dashboard: location_key => [connected, slot_no,
     * reserved]. Plus whether a NEW location could be connected right now.
     *
     * @return array{byKey:array<string,array{connected:bool,slot_no:int,reserved:bool}>, limit:?int, used:int, connected:int, has_free:bool}
     */
    public function snapshot(int $tenantId): array
    {
        $slots = $this->slots($tenantId);
        $byKey = [];
        foreach ($slots as $s) {
            $byKey[$s->location_key] = [
                'connected' => $s->isConnected(),
                'slot_no' => $s->slot_no,
                'reserved' => ! $s->isConnected(),
            ];
        }
        $limit = $this->limit($tenantId);
        $used = $slots->count();

        return [
            'byKey' => $byKey,
            'limit' => $limit,
            'used' => $used,
            'connected' => $slots->where('status', PlanLocationSlot::STATUS_CONNECTED)->count(),
            'has_free' => $limit === null || $used < $limit,
        ];
    }

    /**
     * Reconcile slots after a sync:
     *   1. re-link every slot to the freshly-synced GbpLocation row and apply its
     *      visibility (connected → visible, disconnected → hidden);
     *   2. first time only (no slots yet) → bootstrap: adopt the currently-visible
     *      locations as connected slots (up to the limit) so existing setups and
     *      the first-ever sync keep a populated dashboard;
     *   3. any location WITHOUT a slot → hidden (must be connected explicitly).
     */
    public function reconcile(int $tenantId): void
    {
        $locations = GbpLocation::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
        $byKey = $locations->keyBy(fn (GbpLocation $l) => $this->keyFor($l));

        $slots = $this->slots($tenantId);

        // 1) Re-link existing slots + apply their visibility.
        foreach ($slots as $slot) {
            $loc = $byKey->get($slot->location_key);
            if ($loc) {
                if ($slot->gbp_location_id !== $loc->id) {
                    $slot->update(['gbp_location_id' => $loc->id]);
                }
                $this->setVisible($loc, $slot->isConnected());
            }
        }

        // 2) Bootstrap when the tenant has no slots yet.
        if ($slots->isEmpty()) {
            $limit = $this->limit($tenantId);
            // Prefer the locations already marked visible; else the busiest one so
            // the dashboard is never empty after the first sync.
            $candidates = $locations->where('is_hidden', false)->sortByDesc('review_count')->values();
            if ($candidates->isEmpty()) {
                $top = $locations->sortByDesc('review_count')->first();
                $candidates = $top ? collect([$top]) : collect();
            }
            $no = 0;
            foreach ($candidates as $loc) {
                if ($limit !== null && $no >= $limit) {
                    break;
                }
                $no++;
                $s = new PlanLocationSlot([
                    'plan_id' => $this->planLimits->planFor($tenantId)?->id,
                    'slot_no' => $no,
                    'gbp_location_id' => $loc->id,
                    'location_key' => $this->keyFor($loc),
                    'location_name' => $loc->name,
                    'status' => PlanLocationSlot::STATUS_CONNECTED,
                ]);
                $s->tenant_id = $tenantId;
                $s->save();
                $this->setVisible($loc, true);
            }
        }

        // 3) Everything without a slot is not connected → hidden.
        $slotKeys = $this->slots($tenantId)->pluck('location_key')->all();
        foreach ($locations as $loc) {
            if (! in_array($this->keyFor($loc), $slotKeys, true) && ! $loc->is_hidden) {
                $this->setVisible($loc, false);
            }
        }
    }

    /** Toggle a location's dashboard visibility without touching the slot. */
    private function setVisible(GbpLocation $loc, bool $visible): void
    {
        if ((bool) $loc->is_hidden === ! $visible) {
            return; // already in the desired state
        }
        $loc->forceFill(['is_hidden' => ! $visible])->save();
    }
}
