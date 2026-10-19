<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The high-volume table. external_message_id is Meta's wamid; the unique
 * (tenant_id, external_message_id) is the idempotency guard so a redelivered
 * webhook never creates a duplicate. Designed for month-range partitioning
 * later on (tenant_id, conversation_id, created_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('whatsapp_phone_number_id')->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('direction', 16);            // inbound | outbound
            $table->string('type', 32)->default('text'); // text|image|document|video|audio|location|interactive|button|template|contacts|sticker|reaction
            $table->text('body')->nullable();
            $table->json('payload')->nullable();         // full structured Meta payload for the type
            $table->string('external_message_id')->nullable()->comment('Meta wamid');
            $table->string('reply_to_external_id')->nullable();

            // queued | sent | delivered | read | failed
            $table->string('status', 16)->default('queued');
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // idempotency: a wamid is unique within a tenant
            $table->unique(['tenant_id', 'external_message_id'], 'messages_tenant_external_unique');
            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'messages_tenant_conv_created_index');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
