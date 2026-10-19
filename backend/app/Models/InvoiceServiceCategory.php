<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A category grouping billable services. Tenant-owned. */
class InvoiceServiceCategory extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = ['tenant_id', 'name'];

    public function services(): HasMany
    {
        return $this->hasMany(InvoiceService::class, 'invoice_service_category_id');
    }
}
