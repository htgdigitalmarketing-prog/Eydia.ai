<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI credit system (pay-per-use). Credits are usage units — distinct from the
 * money wallet. Each tenant has one credit wallet with a monthly allowance from
 * its plan; every change is written to the credit ledger for a full audit trail.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->integer('balance')->default(0);
            $table->integer('monthly_allowance')->default(0); // credits granted per plan period
            $table->timestamp('reset_at')->nullable();        // next monthly top-up
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('credit_ledgers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('credit_wallet_id')->constrained('credit_wallets')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('delta');          // signed: -cost on use, +amount on top-up
            $table->integer('balance_after');
            $table->string('action', 48);      // ai_chat, ai_keyword_gen, topup, monthly_reset, …
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledgers');
        Schema::dropIfExists('credit_wallets');
    }
};
