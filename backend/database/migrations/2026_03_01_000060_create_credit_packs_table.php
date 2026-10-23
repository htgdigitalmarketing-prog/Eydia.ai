<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide credit packs clients can buy to top up their AI credits. Price
 * is GST-INCLUSIVE; the base/GST split is derived from gst_rate at display time.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_packs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->unsignedInteger('credits');
            $table->decimal('price', 10, 2);            // incl GST, INR
            $table->unsignedTinyInteger('gst_rate')->default(18);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packs');
    }
};
