<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of the seller details, customer billing details and GST breakdown at
 * the time the invoice was generated, so past invoices never change when the
 * Super Admin later updates the seller settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('line_items');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
