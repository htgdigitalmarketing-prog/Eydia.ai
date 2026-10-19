<?php

namespace App\Modules\Team\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * A company (workspace owner) manages its own people. Two in-company roles:
 *   - Admin ("tenant-owner"): full access to everything in the workspace.
 *   - Agent ("agent"): sees ONLY the features the admin ticked when creating them.
 *
 * Agents get their features as direct permissions, so the frontend nav (gated by
 * user.permissions) shows exactly what was ticked.
 */
class TeamController extends Controller
{
    private const ADMIN_ROLE = 'tenant-owner';
    private const AGENT_ROLE = 'agent';

    /**
     * Feature catalog the admin ticks for an agent. key => [label, permissions].
     *
     * These are the day-to-day, customer-facing tools an agent may be given.
     * OWNER-ONLY tools are deliberately excluded so an agent can never be granted
     * them: WhatsApp number setup (tokens/registration), managing other agents,
     * the team, and billing all stay with the Admin (owner).
     */
    private const FEATURES = [
        'inbox'       => ['label' => 'Inbox (chats)',   'perms' => ['conversations.view', 'conversations.reply', 'conversations.note', 'conversations.tag', 'conversations.status', 'conversations.assign']],
        'contacts'    => ['label' => 'Contacts',        'perms' => ['contacts.view', 'contacts.create', 'contacts.update']],
        'campaigns'   => ['label' => 'Campaigns',       'perms' => ['campaigns.view', 'campaigns.create']],
        'social'      => ['label' => 'Social',          'perms' => ['campaigns.view', 'campaigns.create']],
        'automations' => ['label' => 'Automations',     'perms' => ['workflows.view', 'workflows.create']],
        'chatbot'     => ['label' => 'Chatbot',         'perms' => ['bots.view', 'bots.create']],
        'templates'   => ['label' => 'Templates (view)', 'perms' => ['templates.view']],
        'analytics'   => ['label' => 'Analytics',       'perms' => ['analytics.view']],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('team.view');

        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->where('is_super_admin', false)
            ->orderBy('name')
            ->get();

        return $this->ok([
            'members' => $users->map(fn (User $u) => $this->memberArray($u)),
            // A company owner may only create Agents — never another Admin.
            'roles' => [
                ['value' => 'agent', 'label' => 'Agent'],
            ],
            'features' => collect(self::FEATURES)->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label']])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('team.invite');
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->where(fn ($q) => $q->where('tenant_id', $tenantId))],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(['agent'])], // owners create Agents only
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(array_keys(self::FEATURES))],
        ]);

        // Enforce the plan's agent limit (dynamic — read from the plan, not hard-coded).
        app(\App\Modules\Billing\Services\PlanLimitService::class)->assertCanAddAgent($tenantId);

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->applyAccess($user, $data['role'], $data['features'] ?? []);

        return $this->ok(['member' => $this->memberArray($user->fresh())], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('team.update');
        $user = $this->findMember($request, $uuid);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'required', 'string', Rule::in(['agent'])], // cannot promote to Admin
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(array_keys(self::FEATURES))],
        ]);

        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }
        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
        }
        if (isset($data['role']) || array_key_exists('features', $data)) {
            $role = $data['role'] ?? $this->roleKey($user);
            $features = $data['features'] ?? $this->featureKeys($user);
            $this->applyAccess($user, $role, $features);
        }

        return $this->ok(['member' => $this->memberArray($user->fresh())]);
    }

    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('team.update');
        $user = $this->findMember($request, $uuid);

        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot deactivate your own account.', [], 422);
        }
        $user->update(['status' => $user->status === 'active' ? 'suspended' : 'active']);

        return $this->ok(['member' => $this->memberArray($user->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('team.remove');
        $user = $this->findMember($request, $uuid);

        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot remove your own account.', [], 422);
        }
        $user->delete();

        return $this->ok(['message' => 'Team member removed.']);
    }

    // ---- access ----

    private function applyAccess(User $user, string $role, array $features): void
    {
        if ($role === 'admin') {
            Role::findOrCreate(self::ADMIN_ROLE, 'api');
            $user->syncRoles([self::ADMIN_ROLE]);
            $user->syncPermissions([]); // full access via role
            return;
        }

        Role::findOrCreate(self::AGENT_ROLE, 'api');
        $user->syncRoles([self::AGENT_ROLE]);

        $perms = collect($features)
            ->flatMap(fn ($key) => self::FEATURES[$key]['perms'] ?? [])
            ->unique()->values()->all();
        $user->syncPermissions($perms);
    }

    private function findMember(Request $request, string $uuid): User
    {
        return User::where('uuid', $uuid)
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('is_super_admin', false)
            ->firstOrFail();
    }

    private function roleKey(User $user): string
    {
        return $user->hasRole(self::ADMIN_ROLE) ? 'admin' : 'agent';
    }

    private function featureKeys(User $user): array
    {
        if ($user->hasRole(self::ADMIN_ROLE)) {
            return array_keys(self::FEATURES);
        }
        $has = $user->getAllPermissions()->pluck('name')->all();
        $keys = [];
        foreach (self::FEATURES as $key => $f) {
            if (in_array($f['perms'][0], $has, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    private function memberArray(User $u): array
    {
        $roleKey = $this->roleKey($u);
        return [
            'id' => $u->uuid,
            'name' => $u->name,
            'email' => $u->email,
            'avatar_url' => $u->avatar_url,
            'status' => $u->status,
            'role' => $roleKey,
            'role_label' => $roleKey === 'admin' ? 'Admin' : 'Agent',
            'features' => $this->featureKeys($u),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
