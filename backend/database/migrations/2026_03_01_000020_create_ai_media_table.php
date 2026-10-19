<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Media generation (ReviewFlow §22): text-to-image results, stored as base64
 * data URIs (MEDIUMTEXT — TEXT's 64 KB cap is too small for an image).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('type', 32)->default('image');
            $table->text('prompt');
            $table->mediumText('image_data')->nullable(); // data: URI (base64)
            $table->string('model', 64)->nullable();
            $table->string('source', 16)->default('ai'); // ai | fallback
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_media');
    }
};
