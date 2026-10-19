<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_flow_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bot_flow_id')->constrained('bot_flows')->cascadeOnDelete();
            $table->string('node_key', 64);
            // message | question | buttons | list | collect_input | assign_agent | set_field | handoff | end
            $table->string('type', 32);
            $table->json('config')->nullable();       // prompt text, buttons, save-to field, next mapping
            $table->float('position_x')->default(0);
            $table->float('position_y')->default(0);
            $table->timestamps();

            $table->unique(['bot_flow_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_flow_nodes');
    }
};
