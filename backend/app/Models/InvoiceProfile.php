<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant billing profile for the customer-invoicing tool: seller details,
 * bank info, invoice prefix and the running invoice number. One row per tenant.
 */
class InvoiceProfile extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'company_name', 'business_type', 'phone', 'email', 'gstin',
        'address', 'state', 'logo', 'bank_name', 'bank_account', 'ifsc',
        'invoice_prefix', 'next_invoice_number', 'default_gst', 'currency', 'round_total',
    ];

    protected $casts = [
        'next_invoice_number' => 'integer',
        'default_gst' => 'decimal:2',
        'round_total' => 'boolean',
    ];

    // In-memory defaults so a freshly firstOrCreate()'d profile already has a
    // prefix and starting number (the DB column defaults are not reflected back
    // into the new model instance, which would otherwise yield "-0000" numbers).
    protected $attributes = [
        'invoice_prefix' => 'INV',
        'next_invoice_number' => 1,
        'default_gst' => 18,
        'currency' => 'INR',
        'round_total' => false,
    ];
}
