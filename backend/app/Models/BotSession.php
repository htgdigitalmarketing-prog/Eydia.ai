<?php

namespace App\Models;

use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSession extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'bot_flow_id', 'conversation_id', 'contact_id',
        'status', 'current_node_key', 'answers', 'last_interaction_at', 'expires_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'last_interaction_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function botFlow(): BelongsTo
    {
        return $this->belongsTo(BotFlow::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
