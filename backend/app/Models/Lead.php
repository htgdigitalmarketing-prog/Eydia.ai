<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sales lead captured from Google (GBP / Google Forms), Meta (Facebook /
 * Instagram lead ads), WhatsApp, a website form, or entered manually. Tenant-owned.
 */
class Lead extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes, TracksBlame;

    /** Normalized lead origin. */
    public const SOURCES = ['GOOGLE', 'META', 'WHATSAPP', 'WEBSITE', 'MANUAL'];

    /** Pipeline stages (Kanban columns). */
    public const STAGES = ['NEW', 'CONTACTED', 'FOLLOW_UP', 'APPOINTMENT', 'CONVERTED', 'LOST'];

    protected $fillable = [
        'tenant_id', 'name', 'phone', 'email', 'company', 'source', 'stage',
        'score', 'value', 'notes', 'assigned_agent_id', 'external_id', 'meta',
        'last_contacted_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'value' => 'decimal:2',
        'meta' => 'array',
        'last_contacted_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }
}
