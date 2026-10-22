<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the keyword chatbot into a button-driven lead funnel: rules can carry
 * quick-reply buttons and CRM actions (tag/stage/human handoff), and a chatbot
 * can show a welcome menu with buttons. Powers the one-click "GBP Lead
 * Automation" flow (Eydia marketing/lead doc, section 3).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chatbot_rules', function (Blueprint $table) {
            $table->json('buttons')->nullable()->after('response_text');      // up to 3 quick-reply titles
            $table->string('set_tag')->nullable()->after('template_name');     // CRM funnel tag
            $table->string('set_stage', 24)->nullable()->after('set_tag');      // Lead pipeline stage
            $table->boolean('notify_agent')->default(false)->after('set_stage');// "talk to a human"
        });
        Schema::table('chatbots', function (Blueprint $table) {
            $table->json('welcome_buttons')->nullable()->after('welcome_message');
            $table->boolean('is_lead_flow')->default(false)->after('welcome_buttons');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_rules', function (Blueprint $table) {
            $table->dropColumn(['buttons', 'set_tag', 'set_stage', 'notify_agent']);
        });
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn(['welcome_buttons', 'is_lead_flow']);
        });
    }
};
