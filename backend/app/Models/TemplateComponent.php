<?php

namespace App\Models;

use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateComponent extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'template_id', 'type', 'format', 'text',
        'variable_count', 'example', 'buttons', 'sort_order',
    ];

    protected $casts = [
        'example' => 'array',
        'buttons' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
