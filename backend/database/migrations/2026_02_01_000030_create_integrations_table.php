<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant third-party connections (OAuth tokens etc). One row per
 * (tenant, provider): GOOGLE_GBP, GOOGLE_LEADS, META_LEADS. Tokens are
 * encrypted at rest via the model's casts. Powers live GBP sync and the
 * Google/Meta lead-ad imports; a tenant with no row simply has nothing
 * connected, so dashboards fall back to cached rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // GOOGLE_GBP | GOOGLE_LEADS | META_LEADS
            $table->string('provider', 32);
            $table->string('status', 16)->default('connected'); // connected | expired | revoked
            $table->text('access_token')->nullable();  // encrypted
            $table->text('refresh_token')->nullable();  // encrypted
            $table->timestamp('expires_at')->nullable();
            // The provider's own account identifier (Google "accounts/123",
            // Meta page/business id). Used to scope imports and dedupe.
            $table->string('external_account_id')->nullable();
            $table->string('external_account_name')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable(); // scopes, granted fields, subscribed page/form ids
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
