<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled follow-up message in the GBP Lead Automation drip: a nudge,
 * long-term drip, info follow-up, cold re-engagement or pre-demo reminder,
 * queued for a conversation and sent by the scheduler when its due time passes.
 */
class LeadFlowTask extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    /** Kinds that a fresh customer reply cancels (the clock resets). */
    public const REPLY_CANCELS = ['nudge', 'drip'];

    protected $fillable = [
        'tenant_id', 'conversation_id', 'contact_id', 'whatsapp_phone_number_id',
        'lead_id', 'kind', 'body', 'buttons', 'template_name', 'due_at', 'status', 'sent_at',
    ];

    protected $casts = [
        'buttons' => 'array',
        'due_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsappPhoneNumber::class, 'whatsapp_phone_number_id');
    }
}
