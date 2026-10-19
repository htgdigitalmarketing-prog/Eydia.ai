<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BotFlow extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes, TracksBlame;

    protected $fillable = [
        'tenant_id', 'name', 'status', 'trigger_config', 'entry_node_key',
    ];

    protected $casts = [
        'trigger_config' => 'array',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(BotFlowNode::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(BotSession::class);
    }
}
