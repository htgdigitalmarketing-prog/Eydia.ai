<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds scheduling to Google Business Profile posts & photos so content can be
 * queued ("SCHEDULED") and auto-published when due. Offer/Event specifics
 * (coupon code, terms, event title, start/end) ride in the existing `meta` JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('gbp_posts', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('published_at');
            $table->index(['status', 'scheduled_at']);
        });
        Schema::table('gbp_photos', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('published_at');
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('gbp_posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
        Schema::table('gbp_photos', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
    }
};
