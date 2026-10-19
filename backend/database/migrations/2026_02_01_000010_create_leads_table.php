<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales leads captured from Google, Meta, WhatsApp, website forms or manual
 * entry. Tenant-owned; drives the Lead Management dashboard pipeline.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('source', 32)->default('MANUAL'); // GOOGLE | META | WHATSAPP | WEBSITE | MANUAL
            $table->string('stage', 32)->default('NEW');      // NEW | CONTACTED | FOLLOW_UP | APPOINTMENT | CONVERTED | LOST
            $table->unsignedTinyInteger('score')->default(0); // 0-100 lead quality
            $table->decimal('value', 12, 2)->nullable();      // estimated deal value
            $table->text('notes')->nullable();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_id')->nullable()->comment('ID from Google/Meta so sync is idempotent');
            $table->timestamp('last_contacted_at')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'stage']);
            $table->index(['tenant_id', 'source']);
            $table->unique(['tenant_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
