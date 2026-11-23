<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform "Our Work" — portfolio items the super admin shows on the public
 * landing page, grouped by category (Websites, Web/Android app, Google Ads,
 * Meta Ads, Google Business Profile, …). Not tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('category')->index();      // e.g. "Websites", "Google Ads"
            $table->string('image_url', 1000)->nullable();
            $table->string('link', 500)->nullable();   // live URL / case-study link
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
    }
};
