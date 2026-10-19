<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One wallet per tenant. balance_minor is stored in the smallest currency unit
 * (paise/cents) as a signed bigint — never floats for money. Balance only ever
 * changes via a wallet_transactions row inside a DB transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('currency', 3)->default('INR');
            $table->bigInteger('balance_minor')->default(0);
            $table->bigInteger('reserved_minor')->default(0);
            $table->boolean('auto_recharge')->default(false);
            $table->bigInteger('auto_recharge_threshold_minor')->nullable();
            $table->bigInteger('auto_recharge_amount_minor')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
