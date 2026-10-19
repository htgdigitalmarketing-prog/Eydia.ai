<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_sends', function (Blueprint $table) {
            $table->json('components')->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_sends', function (Blueprint $table) {
            $table->dropColumn('components');
        });
    }
};
