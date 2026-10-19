<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans are global catalog rows (not tenant-owned). Limits are stored as a JSON
 * feature map so new limits can be added without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('billing_period', 16)->default('monthly'); // monthly | yearly
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            // { whatsapp_numbers, agents, contacts, campaigns, workflows, storage_mb, api_access, ... }
            $table->json('limits')->nullable();
            $table->json('features')->nullable();
            $table->integer('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('stripe_price_id')->nullable();
            $table->string('razorpay_plan_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
