<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled, time-based follow-ups for the GBP Lead Automation funnel
 * (Eydia marketing/lead doc, section 3): the 24h no-reply nudge, the 3-day
 * long-term drip, the 2-day "did you see this?" after details are sent, the
 * 14-day re-engagement of a cold lead, and the pre-demo reminder.
 *
 * Each row is one queued WhatsApp message for a conversation at a due time.
 * A reply from the customer cancels the pending nudge/drip (resets the clock);
 * a human handoff cancels everything pending for that chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_flow_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('whatsapp_phone_number_id')->nullable()->constrained('whatsapp_phone_numbers')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            // nudge | drip | info_followup | cold_reengage | demo_reminder
            $table->string('kind', 32);
            $table->text('body');
            $table->json('buttons')->nullable();
            $table->string('template_name')->nullable()->comment('Optional approved template for sends outside the 24h window');

            $table->timestamp('due_at');
            $table->string('status', 16)->default('pending'); // pending | sent | cancelled | failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_flow_tasks');
    }
};
