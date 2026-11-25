<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Studio media is now kept per GBP location, so each connected location has
 * its own independent AI Studio library. Nullable so existing (shared) media and
 * media generated without an active location keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_media', function (Blueprint $table) {
            $table->foreignId('gbp_location_id')->nullable()->after('tenant_id')
                ->constrained('gbp_locations')->nullOnDelete();
            $table->index(['tenant_id', 'gbp_location_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gbp_location_id');
        });
    }
};
