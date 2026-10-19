<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkSend extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = [
        'tenant_id', 'whatsapp_phone_number_id', 'template_name',
        'language', 'components', 'status', 'total', 'sent_count', 'failed_count', 'created_by',
    ];

    protected $casts = [
        'components' => 'array',
    ];

    protected $hidden = [
        'components',
    ];

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsappPhoneNumber::class, 'whatsapp_phone_number_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BulkSendRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
