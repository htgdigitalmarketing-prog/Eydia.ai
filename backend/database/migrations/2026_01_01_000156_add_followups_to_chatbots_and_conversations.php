<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto follow-ups: when a customer goes quiet, send up to two gentle nudges
 * (at +6h and +12h) asking if they want to proceed. Config lives on the chatbot;
 * per-conversation progress is tracked on the conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->boolean('followup_enabled')->default(false)->after('ai_instructions');
            $table->text('followup_message')->nullable()->after('followup_enabled');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_customer_message_at')->nullable()->after('last_message_at');
            $table->unsignedTinyInteger('followup_stage')->default(0)->after('last_customer_message_at');
            $table->index(['status', 'followup_stage']);
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn(['followup_enabled', 'followup_message']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['status', 'followup_stage']);
            $table->dropColumn(['last_customer_message_at', 'followup_stage']);
        });
    }
};
