<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-call AI usage ledger. Every provider call (Gemini / OpenAI / Anthropic)
 * records its model, tokens and computed USD cost here, so the super admin can
 * see exactly how much is spent per provider and per model. Platform-wide
 * (tenant_id is best-effort); additive and never required by the AI call itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('provider', 20)->index();      // gemini | openai | anthropic
            $table->string('model', 100);
            $table->string('operation', 20)->default('text'); // text | vision | image
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('images')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0); // USD × 1,000,000
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
