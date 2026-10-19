<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's WhatsApp Business Account (WABA) obtained via Embedded Signup.
 * The System User access token is encrypted at rest (cast: 'encrypted') and
 * never leaves the backend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_business_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('waba_id')->comment('Meta WhatsApp Business Account ID');
            $table->string('business_portfolio_id')->nullable();
            $table->string('name')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('timezone_id')->nullable();
            // encrypted System User token (production credential)
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('message_template_namespace')->nullable();
            // connected | disconnected | pending | error
            $table->string('status', 32)->default('pending');
            $table->timestamp('connected_at')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'waba_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_business_accounts');
    }
};
