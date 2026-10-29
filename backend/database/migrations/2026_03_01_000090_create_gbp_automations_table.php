<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant Google Business Profile automation settings: the ON/OFF switches
 * for each automated feature (AI review replies, AI posts, AI photos, the QR
 * review-assist experience) plus the content cadence. One row per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbp_automations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('gbp_location_id')->nullable()->constrained('gbp_locations')->nullOnDelete();
            $table->string('slug')->nullable()->unique(); // public review-assist page

            // Feature switches.
            $table->boolean('auto_review_replies')->default(false);
            $table->boolean('auto_posts')->default(false);
            $table->boolean('auto_photos')->default(false);
            $table->boolean('qr_review_assist')->default(false);

            // Content strategy.
            $table->string('reply_tone')->default('warm, friendly and professional');
            $table->unsignedTinyInteger('post_frequency_days')->default(3);
            $table->unsignedTinyInteger('post_queue_target')->default(4);

            $table->timestamp('last_run_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_automations');
    }
};
