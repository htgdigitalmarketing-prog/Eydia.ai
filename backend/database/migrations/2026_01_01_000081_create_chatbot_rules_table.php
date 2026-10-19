<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single keyword → response rule belonging to a chatbot. Rules are evaluated
 * in ascending priority order; the first match wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('chatbot_id')->constrained('chatbots')->cascadeOnDelete();
            $table->string('keyword');
            $table->string('match_type', 16)->default('contains'); // exact | contains | starts_with | regex
            $table->text('response_text')->nullable();
            $table->string('response_type', 16)->default('text');   // text | template
            $table->string('template_name')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['chatbot_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_rules');
    }
};
