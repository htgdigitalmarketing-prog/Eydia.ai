<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured node records (never one opaque JSON blob for the whole workflow).
 * node_key is the React Flow node id, used by edges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('node_key', 64)->comment('React Flow node id');
            // trigger | condition | action | wait
            $table->string('family', 16);
            // send_message | send_template | add_tag | wait | webhook | condition_has_tag ...
            $table->string('type', 48);
            $table->json('config')->nullable();
            $table->float('position_x')->default(0);
            $table->float('position_y')->default(0);
            $table->boolean('is_entry')->default(false);
            $table->timestamps();

            $table->unique(['workflow_id', 'node_key']);
            $table->index('workflow_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_nodes');
    }
};
