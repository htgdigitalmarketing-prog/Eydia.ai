<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Google account often manages many locations (an agency/group account), so a
 * sync can pull in locations the tenant doesn't want to manage here. This flag
 * lets them hide those so only their own location(s) drive the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gbp_locations', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_verified');
        });
    }

    public function down(): void
    {
        Schema::table('gbp_locations', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
