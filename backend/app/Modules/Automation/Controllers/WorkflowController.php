<?php

namespace App\Modules\Automation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Modules\Automation\Resources\WorkflowResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $query = Workflow::withCount(['nodes', 'executions'])
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $workflows = $query->paginate($request->integer('per_page', 25));

        return $this->ok([
            'workflows' => WorkflowResource::collection($workflows),
            'meta' => [
                'current_page' => $workflows->currentPage(),
                'last_page' => $workflows->lastPage(),
                'total' => $workflows->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger_type' => ['required', 'string', 'in:new_contact,incoming_message,keyword,tag_added,tag_removed,scheduled'],
            'trigger_config' => ['nullable', 'array'],
        ]);

        $workflow = Workflow::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'trigger_type' => $data['trigger_type'],
            'trigger_config' => $data['trigger_config'] ?? null,
            'status' => 'draft',
            'version' => 1,
        ]);

        return $this->ok([
            'workflow' => new WorkflowResource($workflow),
        ], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $workflow = Workflow::where('uuid', $uuid)
            ->with(['nodes', 'edges'])
            ->withCount(['nodes', 'executions'])
            ->firstOrFail();

        return $this->ok([
            'workflow' => new WorkflowResource($workflow),
        ]);
    }

    public function update(string $uuid, Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $workflow = Workflow::where('uuid', $uuid)->firstOrFail();

        if (!in_array($workflow->status, ['draft', 'paused'])) {
            return $this->fail('Workflow can only be updated when draft or paused.', [], 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger_type' => ['sometimes', 'required', 'string', 'in:new_contact,incoming_message,keyword,tag_added,tag_removed,scheduled'],
            'trigger_config' => ['nullable', 'array'],
        ]);

        $workflow->update($data);

        return $this->ok([
            'workflow' => new WorkflowResource($workflow->fresh()),
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $workflow = Workflow::where('uuid', $uuid)->firstOrFail();

        if ($workflow->status !== 'draft') {
            return $this->fail('Only draft workflows can be deleted.', [], 422);
        }

        $workflow->delete();

        return $this->ok(['message' => 'Workflow deleted.']);
    }

    public function saveCanvas(string $uuid, Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $workflow = Workflow::where('uuid', $uuid)->firstOrFail();

        if (!in_array($workflow->status, ['draft', 'paused'])) {
            return $this->fail('Canvas can only be saved when workflow is draft or paused.', [], 422);
        }

        $data = $request->validate([
            'nodes' => ['required', 'array'],
            'nodes.*.node_key' => ['required', 'string'],
            'nodes.*.family' => ['required', 'string', 'in:trigger,condition,action,wait'],
            'nodes.*.type' => ['required', 'string'],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.position_x' => ['required', 'numeric'],
            'nodes.*.position_y' => ['required', 'numeric'],
            'nodes.*.is_entry' => ['sometimes', 'boolean'],
            'edges' => ['required', 'array'],
            'edges.*.source_node_key' => ['required', 'string'],
            'edges.*.target_node_key' => ['required', 'string'],
            'edges.*.branch' => ['nullable', 'string'],
            'edges.*.condition' => ['nullable', 'array'],
        ]);

        $tenantId = $request->user()->tenant_id;

        // Delete existing nodes and edges, then bulk-create the new ones
        $workflow->nodes()->delete();
        $workflow->edges()->delete();

        foreach ($data['nodes'] as $node) {
            $workflow->nodes()->create([
                'tenant_id' => $tenantId,
                'node_key' => $node['node_key'],
                'family' => $node['family'],
                'type' => $node['type'],
                'config' => $node['config'] ?? null,
                'position_x' => $node['position_x'],
                'position_y' => $node['position_y'],
                'is_entry' => $node['is_entry'] ?? false,
            ]);
        }

        foreach ($data['edges'] as $edge) {
            $workflow->edges()->create([
                'tenant_id' => $tenantId,
                'source_node_key' => $edge['source_node_key'],
                'target_node_key' => $edge['target_node_key'],
                'branch' => $edge['branch'] ?? 'default',
                'condition' => $edge['condition'] ?? null,
            ]);
        }

        $workflow->load(['nodes', 'edges']);

        return $this->ok([
            'workflow' => new WorkflowResource($workflow),
        ]);
    }

    public function activate(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $workflow = Workflow::where('uuid', $uuid)->firstOrFail();

        if (!in_array($workflow->status, ['draft', 'paused'])) {
            return $this->fail('Workflow can only be activated from draft or paused status.', [], 422);
        }

        $entryNode = $workflow->nodes()->where('is_entry', true)->first();

        if (!$entryNode) {
            return $this->fail('Workflow must have at least one entry node before activation.', [], 422);
        }

        $workflow->update([
            'status' => 'active',
            'activated_at' => now(),
        ]);

        return $this->ok([
            'workflow' => new WorkflowResource($workflow->fresh()),
        ]);
    }

    public function pause(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $workflow = Workflow::where('uuid', $uuid)->firstOrFail();

        if ($workflow->status !== 'active') {
            return $this->fail('Only active workflows can be paused.', [], 422);
        }

        $workflow->update(['status' => 'paused']);

        return $this->ok([
            'workflow' => new WorkflowResource($workflow->fresh()),
        ]);
    }

    public function executions(string $uuid, Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $workflow = Workflow::where('uuid', $uuid)->firstOrFail();

        $executions = $workflow->executions()
            ->with('contact:id,uuid,name,phone')
            ->orderByDesc('started_at')
            ->paginate($request->integer('per_page', 25));

        $items = $executions->getCollection()->map(fn ($exec) => [
            'id' => $exec->uuid,
            'status' => $exec->status,
            'current_node_key' => $exec->current_node_key,
            'contact' => $exec->contact ? [
                'id' => $exec->contact->uuid,
                'name' => $exec->contact->name,
                'phone' => $exec->contact->phone,
            ] : null,
            'started_at' => $exec->started_at?->toIso8601String(),
            'finished_at' => $exec->finished_at?->toIso8601String(),
        ]);

        return $this->ok([
            'executions' => $items,
            'meta' => [
                'current_page' => $executions->currentPage(),
                'last_page' => $executions->lastPage(),
                'total' => $executions->total(),
            ],
        ]);
    }
}
