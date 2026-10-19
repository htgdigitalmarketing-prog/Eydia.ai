<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI auto-reply: when no keyword rule matches, an AI can answer using the
 * business's own instructions/knowledge (ai_instructions) as its context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('fallback_message');
            $table->text('ai_instructions')->nullable()->after('ai_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_instructions']);
        });
    }
};
