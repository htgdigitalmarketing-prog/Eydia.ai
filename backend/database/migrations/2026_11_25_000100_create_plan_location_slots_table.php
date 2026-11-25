<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan-based GBP location slots.
 *
 * Each connected Google Business Profile location permanently occupies ONE slot
 * of the tenant's plan (plan.location_limit). A slot is bound to a location by
 * its stable Google location id the first time it is connected and is NEVER
 * re-pointed at a different location — disconnecting only sets status to
 * 'disconnected' and reserves the slot for the SAME location to reconnect later.
 * This is the server-side source of truth for the connect limit, the swap block
 * and slot ownership (the UI only mirrors it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_location_slots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Who first connected this slot (nullable: system/bootstrap rows).
            $table->unsignedBigInteger('user_id')->nullable();
            // The plan active when the slot was taken (nullable: free/no plan).
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedInteger('slot_no');
            // The current local gbp_locations row (re-linked on each sync).
            $table->unsignedBigInteger('gbp_location_id')->nullable();
            // The PERMANENT identity of the location this slot belongs to. Fixed
            // once set; survives re-syncs (which recreate gbp_locations rows).
            $table->string('location_key');
            $table->string('location_name')->nullable();
            // connected | disconnected (disconnected = reserved for same location).
            $table->string('status')->default('connected');
            $table->timestamps();

            $table->unique(['tenant_id', 'slot_no']);
            $table->unique(['tenant_id', 'location_key']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_location_slots');
    }
};
