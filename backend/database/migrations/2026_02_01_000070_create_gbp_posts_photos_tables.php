<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Business Profile content: posts (localPosts) and photos (media) for a
 * connected location. Rows are drafted/stored here and pushed to Google via the
 * Business Profile API; the Google resource name + status are cached back.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('gbp_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_location_id')->constrained('gbp_locations')->cascadeOnDelete();
            $table->string('google_post_name')->nullable(); // accounts/x/locations/y/localPosts/z
            $table->string('type', 24)->default('STANDARD'); // STANDARD | EVENT | OFFER | ALERT
            $table->text('summary');                          // post body
            $table->string('image_url', 1024)->nullable();
            $table->string('cta_type', 32)->nullable();       // LEARN_MORE | CALL | ORDER | BOOK ...
            $table->string('cta_url', 1024)->nullable();
            $table->string('status', 16)->default('DRAFT');   // DRAFT | PUBLISHED | FAILED
            $table->timestamp('published_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->index(['gbp_location_id', 'status']);
        });

        Schema::create('gbp_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_location_id')->constrained('gbp_locations')->cascadeOnDelete();
            $table->string('google_media_name')->nullable();
            $table->string('category', 32)->default('ADDITIONAL'); // COVER | PROFILE | LOGO | ADDITIONAL ...
            $table->string('image_url', 1024);
            $table->string('caption')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->timestamp('published_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->index(['gbp_location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_photos');
        Schema::dropIfExists('gbp_posts');
    }
};
