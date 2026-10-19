<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An AI-generated media item (text-to-image). The image is stored as a base64
 * `data:` URI in a MEDIUMTEXT column. Tenant-scoped automatically.
 */
class AiMedia extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $table = 'ai_media';

    protected $fillable = [
        'tenant_id', 'created_by', 'type', 'prompt', 'image_data', 'model', 'source',
    ];
}
