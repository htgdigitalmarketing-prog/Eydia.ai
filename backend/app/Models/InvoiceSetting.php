<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform seller/company details for invoices. Singleton — one row, managed by
 * the Super Admin. Not tenant-scoped.
 */
class InvoiceSetting extends Model
{
    protected $fillable = [
        'company_name', 'address', 'gstin', 'email', 'phone',
        'tax_details', 'invoice_prefix', 'gst_rate',
    ];

    protected $casts = [
        'gst_rate' => 'integer',
    ];

    /** The single settings row, created with defaults on first access. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'invoice_prefix' => 'INV',
            'gst_rate' => 18,
        ]);
    }

    /** Snapshot used on an invoice at generation time. */
    public function snapshot(): array
    {
        return [
            'company_name' => $this->company_name,
            'address' => $this->address,
            'gstin' => $this->gstin,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_details' => $this->tax_details,
        ];
    }
}
