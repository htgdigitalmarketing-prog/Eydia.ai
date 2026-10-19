<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword-rule chatbots (auto-reply engine). A chatbot is bound to a WhatsApp
 * phone number and matches inbound message text against its ordered rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_phone_number_id')->nullable()
                ->constrained('whatsapp_phone_numbers')->nullOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->text('welcome_message')->nullable();  // sent on first inbound / session start
            $table->text('fallback_message')->nullable();  // sent when no rule matches
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbots');
    }
};
