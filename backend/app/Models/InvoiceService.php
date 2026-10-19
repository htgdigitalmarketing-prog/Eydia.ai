<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A billable service/line-item template with a price and GST rate. Tenant-owned. */
class InvoiceService extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'invoice_service_category_id', 'name', 'price', 'gst_percent', 'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'gst_percent' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(InvoiceServiceCategory::class, 'invoice_service_category_id');
    }
}
