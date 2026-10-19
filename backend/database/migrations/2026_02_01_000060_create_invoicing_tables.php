<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-invoicing tool (ported from ReviewFlow): the tenant bills its own
 * customers. Deliberately separate from the platform's subscription `invoices`
 * table (Razorpay billing) — different purpose, different tables. All rows are
 * tenant-owned.
 */
return new class extends Migration {
    public function up(): void
    {
        // Per-tenant billing profile + invoice numbering (singleton per tenant).
        Schema::create('invoice_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('business_type', 60)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('state', 60)->nullable();
            $table->text('logo')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account', 50)->nullable();
            $table->string('ifsc', 20)->nullable();
            $table->string('invoice_prefix', 10)->default('INV');
            $table->unsignedInteger('next_invoice_number')->default(1);
            $table->decimal('default_gst', 5, 2)->default(18);
            $table->string('currency', 10)->default('INR');
            $table->boolean('round_total')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        Schema::create('invoice_customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('billing_address')->nullable();
            $table->timestamps();

            $table->index(['tenant_id']);
        });

        Schema::create('invoice_service_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['tenant_id']);
        });

        Schema::create('invoice_services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_service_category_id')->nullable()->constrained('invoice_service_categories')->nullOnDelete();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('gst_percent', 5, 2)->default(18);
            $table->string('status', 10)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();

            $table->index(['tenant_id']);
        });

        Schema::create('customer_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_customer_id')->constrained('invoice_customers')->cascadeOnDelete();
            $table->string('invoice_number');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('status', 10)->default('DRAFT'); // DRAFT | SENT | PAID | OVERDUE
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('gst_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('notes', 1000)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->unique(['tenant_id', 'invoice_number']);
        });

        Schema::create('customer_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();
            $table->foreignId('invoice_service_id')->nullable()->constrained('invoice_services')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('gst_percent', 5, 2)->default(18);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id']);
        });

        Schema::create('invoice_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('date');
            $table->string('description');
            $table->string('category', 100)->nullable();
            $table->string('method', 10)->default('OTHER'); // CASH | BANK | UPI | CARD | OTHER
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_items');
        Schema::dropIfExists('customer_invoices');
        Schema::dropIfExists('invoice_expenses');
        Schema::dropIfExists('invoice_services');
        Schema::dropIfExists('invoice_service_categories');
        Schema::dropIfExists('invoice_customers');
        Schema::dropIfExists('invoice_profiles');
    }
};
