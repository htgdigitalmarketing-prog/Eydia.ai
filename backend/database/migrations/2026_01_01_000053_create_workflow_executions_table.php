<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable execution state — survives app restarts. A 'waiting' execution parks
 * on current_node_key with resume_at (timed wait) or waits for an inbound
 * message match (wait-for-reply).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            // running | waiting | completed | failed | cancelled
            $table->string('status', 16)->default('running');
            $table->string('current_node_key', 64)->nullable();
            $table->json('context')->nullable();       // accumulated variables/state
            $table->timestamp('resume_at')->nullable(); // for timed wait nodes
            $table->boolean('waiting_for_reply')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            // scheduler picks up due timed waits
            $table->index(['status', 'resume_at']);
            $table->index(['workflow_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_executions');
    }
};
