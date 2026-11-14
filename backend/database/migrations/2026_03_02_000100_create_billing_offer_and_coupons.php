<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backend-managed pricing: a singleton for per-cycle discounts + the launch-offer
 * countdown, and a coupons table applied on top of the cycle discount at checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Singleton (id=1) — per-cycle discount %, which cycles to show, and the
        // optional limited-time launch offer banner/countdown.
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->json('cycle_discounts')->nullable();   // { "half_yearly": 15, "yearly": 20 }
            $table->json('enabled_cycles')->nullable();     // ["monthly","half_yearly","yearly"]
            $table->string('offer_label')->nullable();      // "LAUNCH PRICE — LIMITED TIME"
            $table->timestamp('offer_ends_at')->nullable(); // countdown target
            $table->boolean('offer_active')->default(false);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();               // stored/compared uppercased
            $table->string('type')->default('percent');     // percent | flat
            $table->decimal('value', 10, 2)->default(0);    // % (0-100) or ₹ off
            $table->decimal('max_discount', 10, 2)->nullable(); // cap for percent coupons (₹)
            $table->decimal('min_amount', 10, 2)->nullable();   // min cart (₹) to qualify
            $table->unsignedInteger('max_uses')->nullable();    // null = unlimited
            $table->unsignedInteger('used_count')->default(0);
            $table->json('cycles')->nullable();             // null = all cycles, else ["yearly"]
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('billing_settings');
    }
};
