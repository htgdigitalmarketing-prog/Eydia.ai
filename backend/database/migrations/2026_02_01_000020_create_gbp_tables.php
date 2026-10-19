<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Business Profile locations and their reviews. Powers the Google
 * Reviews / GBP dashboard. Data is cached from the GBP API on sync.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('gbp_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('google_location_id')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('category')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->unique(['tenant_id', 'google_location_id']);
        });

        Schema::create('gbp_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_location_id')->constrained('gbp_locations')->cascadeOnDelete();
            $table->string('google_review_id')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_photo')->nullable();
            $table->unsignedTinyInteger('rating')->default(5); // 1-5 stars
            $table->text('comment')->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rating']);
            $table->unique(['gbp_location_id', 'google_review_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_reviews');
        Schema::dropIfExists('gbp_locations');
    }
};
