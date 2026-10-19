<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable ledger. Every balance change has a row here with the resulting
 * balance_after_minor for audit. amount_minor is signed (+credit / -debit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('type', 16);              // credit | debit | refund | adjustment
            $table->bigInteger('amount_minor');       // signed
            $table->bigInteger('balance_after_minor');
            $table->string('currency', 3)->default('INR');
            $table->string('description')->nullable();
            $table->nullableMorphs('reference');      // e.g. message, campaign, invoice, gateway payment
            $table->string('idempotency_key')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
