<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            // When on, the AI chat first offers the visitor a FREE instant
            // website, asks for their business name, then builds & sends a link.
            $table->boolean('website_builder')->default(false)->after('ai_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn('website_builder');
        });
    }
};
