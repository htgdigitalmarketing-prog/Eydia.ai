<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A business expense the tenant logs alongside invoicing. Tenant-owned. */
class InvoiceExpense extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const METHODS = ['CASH', 'BANK', 'UPI', 'CARD', 'OTHER'];

    protected $fillable = [
        'tenant_id', 'date', 'description', 'category', 'method', 'amount',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];
}
