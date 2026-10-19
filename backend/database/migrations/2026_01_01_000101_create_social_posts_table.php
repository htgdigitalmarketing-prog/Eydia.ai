<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A social post composed in PiziDesk, published to Facebook and/or Instagram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->text('caption')->nullable();
            $table->text('image_url')->nullable();      // public URL published to Meta
            $table->string('image_path')->nullable();   // local stored path (if uploaded)
            $table->json('targets');                    // ["facebook","instagram"]
            $table->string('status', 16)->default('draft'); // draft|scheduled|published|partial|failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('results')->nullable();        // per-platform post id / error
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
