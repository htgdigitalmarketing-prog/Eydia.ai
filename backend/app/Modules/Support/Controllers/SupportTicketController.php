<?php

namespace App\Modules\Support\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::with('user')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $safe = '%' . $this->escapeLike($search) . '%';
            $query->where(function ($q) use ($safe) {
                $q->where('ticket_id', 'like', $safe)
                  ->orWhere('subject', 'like', $safe);
            });
        }

        $tickets = $query->paginate($request->integer('per_page', 20));

        return $this->ok([
            'tickets' => $tickets->map(fn (SupportTicket $t) => $this->format($t)),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['sometimes', 'in:low,medium,high,urgent'],
            'category' => ['sometimes', 'nullable', 'in:billing,technical,account,other'],
        ]);

        $ticket = SupportTicket::create([
            'tenant_id' => $request->user()->tenant_id,
            'user_id' => $request->user()->id,
            'ticket_id' => SupportTicket::generateTicketId(),
            'subject' => $data['subject'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 'medium',
            'category' => $data['category'] ?? null,
            'status' => 'open',
        ]);

        return $this->ok(['ticket' => $this->format($ticket->load('user'))], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $ticket = SupportTicket::with('user')
            ->where('uuid', $uuid)
            ->firstOrFail();

        return $this->ok(['ticket' => $this->format($ticket)]);
    }

    /** Super admin: reply to a ticket. */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        if (! $user->is_super_admin) {
            return $this->fail('Only super admin can reply to tickets.', [], 403);
        }

        $data = $request->validate([
            'admin_reply' => ['required', 'string', 'max:5000'],
            'status' => ['sometimes', 'in:open,in_progress,resolved,closed'],
        ]);

        $ticket = SupportTicket::withoutGlobalScopes()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $ticket->update([
            'admin_reply' => $data['admin_reply'],
            'replied_at' => now(),
            'status' => $data['status'] ?? 'in_progress',
            'resolved_at' => ($data['status'] ?? '') === 'resolved' ? now() : $ticket->resolved_at,
        ]);

        return $this->ok(['ticket' => $this->format($ticket->fresh()->load('user'))]);
    }

    /** Super admin: list ALL tickets across tenants. */
    public function adminIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->is_super_admin) {
            return $this->fail('Unauthorized.', [], 403);
        }

        $query = SupportTicket::withoutGlobalScopes()
            ->with(['user', 'tenant'])
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'resolved', 'closed')")
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $safe = '%' . $this->escapeLike($search) . '%';
            $query->where(function ($q) use ($safe) {
                $q->where('ticket_id', 'like', $safe)
                  ->orWhere('subject', 'like', $safe);
            });
        }

        $tickets = $query->paginate($request->integer('per_page', 20));

        return $this->ok([
            'tickets' => $tickets->map(fn (SupportTicket $t) => [
                ...$this->format($t),
                'tenant_name' => $t->tenant?->name ?? '—',
            ]),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    private function format(SupportTicket $t): array
    {
        return [
            'id' => $t->uuid,
            'ticket_id' => $t->ticket_id,
            'subject' => $t->subject,
            'description' => $t->description,
            'priority' => $t->priority,
            'status' => $t->status,
            'category' => $t->category,
            'admin_reply' => $t->admin_reply,
            'replied_at' => $t->replied_at?->toIso8601String(),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            'user_name' => $t->user?->name ?? '—',
            'user_email' => $t->user?->email ?? '—',
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
