<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes, TracksBlame;

    protected $fillable = [
        'tenant_id', 'contact_id', 'whatsapp_phone_number_id', 'assigned_agent_id',
        'status', 'unread_count', 'is_bot_active', 'last_message_at',
        'last_message_preview', 'window_expires_at', 'meta',
        'last_customer_message_at', 'followup_stage',
    ];

    protected $casts = [
        'is_bot_active' => 'boolean',
        'last_message_at' => 'datetime',
        'window_expires_at' => 'datetime',
        'last_customer_message_at' => 'datetime',
        'meta' => 'array',
    ];

    /** The Meta 24h customer-service window is open (free-form replies allowed). */
    public function windowIsOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsappPhoneNumber::class, 'whatsapp_phone_number_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ConversationAssignment::class);
    }
}
