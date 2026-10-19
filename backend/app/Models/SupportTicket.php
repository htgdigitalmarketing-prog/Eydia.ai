<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'ticket_id',
        'subject',
        'description',
        'priority',
        'status',
        'category',
        'admin_reply',
        'replied_at',
        'resolved_at',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function generateTicketId(): string
    {
        $prefix = 'TKT';
        $number = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $id = $prefix . '-' . $number;

        while (self::withoutGlobalScopes()->where('ticket_id', $id)->exists()) {
            $number = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $id = $prefix . '-' . $number;
        }

        return $id;
    }
}
