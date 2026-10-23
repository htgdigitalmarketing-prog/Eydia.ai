<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-generated public business microsite + Google Business Profile report.
 * One per tenant/location; a published row is served at /site/{slug}. The GBP
 * snapshot is frozen into `data` at generate time so the page stays stable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_sites', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_location_id')->nullable()->constrained('gbp_locations')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('status', 16)->default('published'); // draft | published
            $table->json('data');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_sites');
    }
};
