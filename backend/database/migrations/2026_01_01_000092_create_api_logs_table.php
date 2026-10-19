<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound Meta Graph API request/response log. Tokens and phone numbers are
 * masked before storage (never log raw secrets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('direction', 16)->default('outbound'); // outbound | inbound
            $table->string('service', 32)->default('meta');
            $table->string('method', 8)->nullable();
            $table->string('endpoint')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('request')->nullable();     // masked
            $table->json('response')->nullable();     // masked
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['service', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
