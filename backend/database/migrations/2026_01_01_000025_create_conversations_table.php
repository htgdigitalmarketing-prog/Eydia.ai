<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One conversation per contact per phone number. Tracks the Meta 24h
 * customer-service window (free-form replies allowed until window_expires_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('whatsapp_phone_number_id')->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            // open | pending | resolved | closed
            $table->string('status', 32)->default('open');
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('is_bot_active')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->text('last_message_preview')->nullable();
            // Meta 24h customer-service window
            $table->timestamp('window_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contact_id', 'whatsapp_phone_number_id'], 'conversations_contact_phone_unique');
            $table->index(['tenant_id', 'status', 'last_message_at']);
            $table->index(['tenant_id', 'assigned_agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
