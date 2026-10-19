<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_execution_id')->constrained('workflow_executions')->cascadeOnDelete();
            $table->string('node_key', 64)->nullable();
            $table->string('node_type', 48)->nullable();
            // entered | executed | skipped | waiting | resumed | error
            $table->string('event', 32);
            $table->json('detail')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index('workflow_execution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_execution_logs');
    }
};
