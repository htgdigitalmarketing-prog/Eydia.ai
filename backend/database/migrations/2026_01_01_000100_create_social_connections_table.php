<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's link to a Facebook Page (and optionally its connected Instagram
 * Business account). The Page access token is used to publish to both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('page_id');
            $table->string('page_name')->nullable();
            $table->text('page_access_token');
            $table->string('ig_user_id')->nullable();
            $table->string('ig_username')->nullable();
            $table->string('status', 16)->default('connected'); // connected | error
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('tenant_id'); // one social connection per workspace
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_connections');
    }
};
