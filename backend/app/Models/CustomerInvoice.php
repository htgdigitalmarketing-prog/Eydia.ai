<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice the tenant issues to one of its customers. Separate from the
 * platform subscription Invoice model. Tenant-owned.
 */
class CustomerInvoice extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const STATUSES = ['DRAFT', 'SENT', 'PAID', 'OVERDUE'];

    protected $fillable = [
        'tenant_id', 'invoice_customer_id', 'invoice_number', 'issue_date', 'due_date',
        'status', 'subtotal', 'gst_total', 'total', 'notes', 'paid_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'gst_total' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(InvoiceCustomer::class, 'invoice_customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerInvoiceItem::class, 'customer_invoice_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'SENT' && $this->due_date && $this->due_date->isPast();
    }
}
