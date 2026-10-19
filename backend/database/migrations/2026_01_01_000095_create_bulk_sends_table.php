<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_sends', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_phone_number_id')->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->string('template_name');
            $table->string('language', 16)->default('en');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('bulk_send_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_send_id')->constrained('bulk_sends')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('status', 16)->default('pending');
            $table->string('wamid')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['bulk_send_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_send_recipients');
        Schema::dropIfExists('bulk_sends');
    }
};
