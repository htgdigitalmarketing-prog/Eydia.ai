<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI review replies + sentiment (ReviewFlow §8): cache an AI-drafted reply the
 * user can edit before posting, and a locally-computed sentiment label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gbp_reviews', function (Blueprint $table) {
            $table->text('draft_reply')->nullable()->after('reply');
            $table->string('sentiment', 16)->nullable()->after('draft_reply');
        });
    }

    public function down(): void
    {
        Schema::table('gbp_reviews', function (Blueprint $table) {
            $table->dropColumn(['draft_reply', 'sentiment']);
        });
    }
};
