<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation settings are now per LOCATION (each connected location keeps its own
 * config + Brand Kit), so the old one-row-per-tenant unique constraint must give
 * way to a (tenant_id, gbp_location_id) unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add the composite unique FIRST: its leftmost column is tenant_id, so the
        // tenant_id foreign key can rely on it once the single-column unique index
        // is dropped (MySQL refuses to drop an index a foreign key still needs).
        Schema::table('gbp_automations', function (Blueprint $table) {
            $table->unique(['tenant_id', 'gbp_location_id']); // one row per tenant+location
        });
        Schema::table('gbp_automations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id']); // gbp_automations_tenant_id_unique
        });
    }

    public function down(): void
    {
        Schema::table('gbp_automations', function (Blueprint $table) {
            $table->unique(['tenant_id']);
        });
        Schema::table('gbp_automations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'gbp_location_id']);
        });
    }
};
