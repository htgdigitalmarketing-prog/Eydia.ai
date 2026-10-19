<?php

namespace App\Modules\Agents\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agents are the tenant's users who can be assigned conversations. This module
 * exposes the roster, per-agent workload/stats, and manual assignment.
 */
class AgentController extends Controller
{
    /** Statuses that count as an agent's live workload. */
    private const OPEN_STATUSES = ['open', 'pending'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $users = User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy('name')
            ->get();

        $activeCounts = $this->activeConversationCounts();
        $handledCounts = $this->handledCounts();

        $agents = $users->map(fn (User $user) => [
            'id' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'roles' => $user->getRoleNames()->values(),
            'active_conversations_count' => (int) ($activeCounts[$user->id] ?? 0),
            'total_handled' => (int) ($handledCounts[$user->id] ?? 0),
        ]);

        return $this->ok(['agents' => $agents]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $user = User::where('uuid', $uuid)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $conversations = Conversation::where('assigned_agent_id', $user->id)
            ->with('contact:id,name,phone')
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (Conversation $c) => [
                'id' => $c->uuid,
                'contact_name' => $c->contact?->name,
                'contact_phone' => $c->contact?->phone,
                'status' => $c->status,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
            ]);

        $activeCounts = $this->activeConversationCounts();
        $handledCounts = $this->handledCounts();

        return $this->ok([
            'agent' => [
                'id' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'roles' => $user->getRoleNames()->values(),
                'active_conversations_count' => (int) ($activeCounts[$user->id] ?? 0),
                'total_handled' => (int) ($handledCounts[$user->id] ?? 0),
                'conversations' => $conversations,
            ],
        ]);
    }

    /** Lightweight roster (id + name) any inbox user can read, for the transfer picker. */
    public function assignable(Request $request): JsonResponse
    {
        $this->authorize('conversations.view');

        $users = User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name']);

        return $this->ok([
            'agents' => $users->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'is_me' => $u->id === $request->user()->id,
            ])->values(),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $data = $request->validate([
            'conversation_id' => ['required', 'string'],
            'agent_id' => ['required', 'string'],
        ]);

        $conversation = Conversation::withoutGlobalScopes()
            ->where('uuid', $data['conversation_id'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $agent = User::where('uuid', $data['agent_id'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        DB::transaction(function () use ($conversation, $agent, $request) {
            $conversation->assignments()
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            $conversation->update(['assigned_agent_id' => $agent->id]);

            ConversationAssignment::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'agent_user_id' => $agent->id,
                'assigned_by' => $request->user()->id,
                'strategy' => 'manual',
                'assigned_at' => now(),
            ]);
        });

        Log::info("Agent assigned", [
            'conversation' => $data['conversation_id'],
            'agent' => $agent->name,
            'by' => $request->user()->name,
        ]);

        return $this->ok(['message' => "Conversation assigned to {$agent->name}."]);
    }

    public function unassign(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $data = $request->validate([
            'conversation_id' => ['required', 'string'],
        ]);

        $conversation = Conversation::withoutGlobalScopes()
            ->where('uuid', $data['conversation_id'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        DB::transaction(function () use ($conversation) {
            $conversation->assignments()
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            $conversation->update(['assigned_agent_id' => null]);
        });

        return $this->ok(['message' => 'Conversation unassigned.']);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $rows = Conversation::query()
            ->whereNotNull('assigned_agent_id')
            ->select('assigned_agent_id', 'status', DB::raw('COUNT(*) as c'))
            ->groupBy('assigned_agent_id', 'status')
            ->get();

        $byAgent = [];
        foreach ($rows as $row) {
            $byAgent[$row->assigned_agent_id][$row->status] = (int) $row->c;
        }

        $users = User::whereIn('id', array_keys($byAgent))
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy('name')
            ->get();

        $stats = $users->map(function (User $user) use ($byAgent) {
            $counts = $byAgent[$user->id] ?? [];
            $open = $counts['open'] ?? 0;
            $pending = $counts['pending'] ?? 0;
            $resolved = $counts['resolved'] ?? 0;
            $closed = $counts['closed'] ?? 0;

            return [
                'id' => $user->uuid,
                'name' => $user->name,
                'open' => $open,
                'pending' => $pending,
                'resolved' => $resolved,
                'closed' => $closed,
                'total' => array_sum($counts),
            ];
        });

        return $this->ok(['stats' => $stats->values()]);
    }

    /** @return array<int,int> keyed by user id */
    private function activeConversationCounts(): array
    {
        return Conversation::query()
            ->whereNotNull('assigned_agent_id')
            ->whereIn('status', self::OPEN_STATUSES)
            ->select('assigned_agent_id', DB::raw('COUNT(*) as c'))
            ->groupBy('assigned_agent_id')
            ->pluck('c', 'assigned_agent_id')
            ->toArray();
    }

    /** @return array<int,int> distinct conversations ever assigned, keyed by user id */
    private function handledCounts(): array
    {
        return ConversationAssignment::query()
            ->whereNotNull('agent_user_id')
            ->select('agent_user_id', DB::raw('COUNT(DISTINCT conversation_id) as c'))
            ->groupBy('agent_user_id')
            ->pluck('c', 'agent_user_id')
            ->toArray();
    }
}
