<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-SEO toolkit tables: tracked keywords, their rank-check history, and
 * competitors watched per GBP location. Powers the rank checker, keyword
 * tracking and competitor analysis features (ported from ReviewFlow).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_location_id')->nullable()->constrained('gbp_locations')->nullOnDelete();
            $table->string('keyword');
            $table->string('city')->nullable();
            $table->string('latest_rank')->nullable(); // "Top 3" | "4-10" | numeric | null
            $table->timestamp('last_checked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->unique(['tenant_id', 'gbp_location_id', 'keyword']);
        });

        Schema::create('seo_rank_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('seo_keyword_id')->constrained('seo_keywords')->cascadeOnDelete();
            $table->string('rank')->nullable();
            $table->string('source', 16)->default('ai'); // places | ai | manual | fallback
            $table->json('meta')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->index(['seo_keyword_id', 'checked_at']);
        });

        Schema::create('seo_competitors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_location_id')->nullable()->constrained('gbp_locations')->nullOnDelete();
            $table->string('name');
            $table->string('city')->nullable();
            $table->text('analysis')->nullable(); // last AI comparison summary
            $table->timestamp('last_analyzed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rank_snapshots');
        Schema::dropIfExists('seo_competitors');
        Schema::dropIfExists('seo_keywords');
    }
};
