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
        Schema::table('gbp_automations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id']);               // gbp_automations_tenant_id_unique
            $table->unique(['tenant_id', 'gbp_location_id']); // one row per tenant+location
        });
    }

    public function down(): void
    {
        Schema::table('gbp_automations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'gbp_location_id']);
            $table->unique(['tenant_id']);
        });
    }
};
