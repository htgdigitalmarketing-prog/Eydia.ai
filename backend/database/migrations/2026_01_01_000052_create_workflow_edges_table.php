<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('source_node_key', 64);
            $table->string('target_node_key', 64);
            // branch label for conditions: 'yes' | 'no' | 'default' | custom
            $table->string('branch', 32)->nullable();
            $table->json('condition')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'source_node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_edges');
    }
};
