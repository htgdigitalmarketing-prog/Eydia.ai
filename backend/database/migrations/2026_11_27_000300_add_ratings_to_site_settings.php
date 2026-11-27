<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homepage "Trusted by" rating cards (App Store / Google Play / Google /
 * Trustpilot), editable by the Super Admin. Stored as JSON on the singleton.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->json('ratings')->nullable()->after('linkedin');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('ratings');
        });
    }
};
