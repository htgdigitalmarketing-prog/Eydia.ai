<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person the tenant talks to. wa_id is the normalized E.164 number without '+'
 * (Meta's contact identifier). Unique per tenant to prevent duplicates.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('wa_id', 32)->comment('Normalized E.164 without +');
            $table->string('phone', 32);
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('tag_list', 255)->nullable();
            $table->string('source', 64)->nullable();       // manual | import | inbound | campaign | api
            $table->foreignId('lead_status_id')->nullable()->constrained('lead_statuses')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('language', 8)->nullable();
            $table->string('country', 2)->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->boolean('opted_out')->default(false);
            $table->timestamp('last_interaction_at')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'wa_id']);
            $table->index(['tenant_id', 'assigned_agent_id']);
            $table->index(['tenant_id', 'lead_status_id']);
            $table->index(['tenant_id', 'last_interaction_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
