<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer the tenant bills via the customer-invoicing tool. Tenant-owned. */
class InvoiceCustomer extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'name', 'phone', 'email', 'gstin', 'billing_address',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class, 'invoice_customer_id');
    }
}
