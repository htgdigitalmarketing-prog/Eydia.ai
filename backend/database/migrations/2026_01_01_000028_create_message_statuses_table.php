<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only status timeline for a message (sent/delivered/read/failed), one
 * row per Meta status callback. Deduped by (message_id, status) so a repeated
 * webhook is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('status', 16);            // sent | delivered | read | failed
            $table->string('conversation_id_meta')->nullable();
            $table->string('pricing_category', 32)->nullable();
            $table->string('pricing_model', 32)->nullable();
            $table->boolean('billable')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_statuses');
    }
};
