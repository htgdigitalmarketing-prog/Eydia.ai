<?php

namespace App\Modules\Automation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'trigger_type' => $this->trigger_type,
            'trigger_config' => $this->trigger_config,
            'version' => $this->version,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'nodes' => $this->whenLoaded('nodes', fn () => $this->nodes->map(fn ($node) => [
                'node_key' => $node->node_key,
                'family' => $node->family,
                'type' => $node->type,
                'config' => $node->config,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
                'is_entry' => $node->is_entry,
            ])),
            'edges' => $this->whenLoaded('edges', fn () => $this->edges->map(fn ($edge) => [
                'source_node_key' => $edge->source_node_key,
                'target_node_key' => $edge->target_node_key,
                'branch' => $edge->branch,
                'condition' => $edge->condition,
            ])),
            'nodes_count' => $this->whenCounted('nodes'),
            'executions_count' => $this->whenCounted('executions'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
