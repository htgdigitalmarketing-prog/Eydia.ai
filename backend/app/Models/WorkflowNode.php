<?php

namespace App\Models;

use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowNode extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'workflow_id', 'node_key', 'family', 'type',
        'config', 'position_x', 'position_y', 'is_entry',
    ];

    protected $casts = [
        'config' => 'array',
        'is_entry' => 'boolean',
        'position_x' => 'float',
        'position_y' => 'float',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
