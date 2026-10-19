<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A phone number under a WABA. phone_number_id is Meta's identifier used on
 * every send. It is globally unique on Meta's side, so we can resolve a tenant
 * from an inbound webhook by phone_number_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_business_account_id')->constrained('whatsapp_business_accounts')->cascadeOnDelete();
            $table->string('phone_number_id')->comment('Meta phone_number_id used on all sends');
            $table->string('display_phone_number');
            $table->string('verified_name')->nullable();
            $table->string('quality_rating', 16)->nullable();   // GREEN | YELLOW | RED
            $table->string('throughput_level', 16)->nullable(); // STANDARD | HIGH
            $table->string('messaging_limit_tier', 16)->nullable();
            $table->string('code_verification_status', 32)->nullable();
            $table->string('name_status', 32)->nullable();
            // pending | registered | disconnected | flagged
            $table->string('status', 32)->default('pending');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_registered')->default(false);
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // phone_number_id is Meta-global-unique — a hard guard for webhook routing
            $table->unique('phone_number_id');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phone_numbers');
    }
};
