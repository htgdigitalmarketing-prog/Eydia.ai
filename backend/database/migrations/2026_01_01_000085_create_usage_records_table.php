<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-delivered-message usage metering (Meta's July-2025 billing model:
 * per-template-message by country + category). Feeds wallet debits and analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('whatsapp_phone_number_id')->nullable()->constrained('whatsapp_phone_numbers')->nullOnDelete();
            $table->string('category', 32)->nullable();   // marketing | utility | authentication | service
            $table->string('country', 2)->nullable();
            $table->boolean('billable')->default(true);
            $table->bigInteger('cost_minor')->default(0);
            $table->string('currency', 3)->default('INR');
            $table->date('usage_date');
            $table->timestamps();

            $table->index(['tenant_id', 'usage_date']);
            $table->index(['tenant_id', 'category', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
