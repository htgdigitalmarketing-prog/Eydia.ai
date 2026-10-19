<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User black list. Numbers here are excluded from all outbound sends (bulk,
 * campaigns) and can optionally be dropped inbound. One row per tenant+phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('name')->nullable();
            $table->string('reason')->nullable();
            $table->string('source', 24)->default('manual'); // manual | opt_out | api
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_entries');
    }
};
