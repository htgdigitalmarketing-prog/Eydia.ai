<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide seller/company details used on generated invoices. Single row
 * (managed by the Super Admin). Not tenant-scoped — this is the seller (the
 * platform), not a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->text('address')->nullable();
            $table->string('gstin')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_details')->nullable(); // e.g. PAN / CIN
            $table->string('invoice_prefix', 16)->default('INV');
            $table->unsignedInteger('gst_rate')->default(18); // percent
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_settings');
    }
};
