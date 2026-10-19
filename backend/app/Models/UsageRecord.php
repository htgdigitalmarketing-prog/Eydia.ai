<?php

namespace App\Models;

use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'message_id', 'whatsapp_phone_number_id', 'category',
        'country', 'billable', 'cost_minor', 'currency', 'usage_date',
    ];

    protected $casts = [
        'billable' => 'boolean',
        'cost_minor' => 'integer',
        'usage_date' => 'date',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
