<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad campaign performance reports (Google / Meta), entered manually or imported.
 * Powers the Ads reporting dashboard (ported from ReviewFlow). Tenant-owned.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ad_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_location_id')->nullable()->constrained('gbp_locations')->nullOnDelete();
            $table->string('network', 16); // GOOGLE | META
            $table->string('campaign');
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_reports');
    }
};
