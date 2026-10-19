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

class Template extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes, TracksBlame;

    protected $fillable = [
        'tenant_id', 'whatsapp_business_account_id', 'meta_template_id',
        'name', 'language', 'category', 'status', 'rejection_reason',
        'quality_score', 'raw', 'last_synced_at',
    ];

    protected $casts = [
        'raw' => 'array',
        'last_synced_at' => 'datetime',
    ];

    /** Meta is the source of truth — approved only if Meta says APPROVED. */
    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    /**
     * The approved BODY text of a template (for showing a template message's real
     * content in the inbox). Returns null if the template/body can't be found.
     */
    public static function bodyText(int $tenantId, string $name, ?string $language = null): ?string
    {
        $tpl = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->when($language, fn ($q) => $q->where('language', $language))
            ->with('components')
            ->first();

        if (! $tpl) {
            return null;
        }

        $body = $tpl->components->firstWhere('type', 'BODY');

        return $body?->text ?: data_get($tpl->raw, 'components.0.text');
    }

    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappBusinessAccount::class, 'whatsapp_business_account_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(TemplateComponent::class);
    }
}
