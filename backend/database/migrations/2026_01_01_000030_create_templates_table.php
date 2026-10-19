<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message templates. Meta is the source of truth for approval status — we sync
 * it, never fabricate an "approved" locally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_business_account_id')->constrained('whatsapp_business_accounts')->cascadeOnDelete();
            $table->string('meta_template_id')->nullable();
            $table->string('name');
            $table->string('language', 16);
            $table->string('category', 32);           // MARKETING | UTILITY | AUTHENTICATION
            // Meta status: APPROVED | PENDING | REJECTED | PAUSED | DISABLED (source of truth = Meta)
            $table->string('status', 32)->default('PENDING');
            $table->string('rejection_reason')->nullable();
            $table->string('quality_score', 16)->nullable();
            $table->json('raw')->nullable();          // full Meta template JSON as last synced
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['whatsapp_business_account_id', 'name', 'language'], 'templates_waba_name_lang_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
