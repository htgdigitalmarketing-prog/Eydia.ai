<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow unlimited AI business-info / instructions: widen ai_instructions from
 * TEXT (~65 KB) to LONGTEXT so the chatbot business info has no practical cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->longText('ai_instructions')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->text('ai_instructions')->nullable()->change();
        });
    }
};
