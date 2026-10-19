<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment history for a conversation (who handled it, when). The current
 * assignee is denormalized onto conversations.assigned_agent_id for fast reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('strategy', 16)->default('manual'); // manual | round_robin | least_active | territory | team
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('unassigned_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_assignments');
    }
};
