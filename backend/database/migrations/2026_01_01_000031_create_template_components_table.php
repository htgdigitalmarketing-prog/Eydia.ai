<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured components of a template (HEADER/BODY/FOOTER/BUTTONS) with their
 * variable count — kept structured (not one blob) so campaigns can validate
 * variable mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('templates')->cascadeOnDelete();
            $table->string('type', 32);               // HEADER | BODY | FOOTER | BUTTONS
            $table->string('format', 32)->nullable(); // TEXT | IMAGE | VIDEO | DOCUMENT | LOCATION
            $table->text('text')->nullable();
            $table->unsignedInteger('variable_count')->default(0);
            $table->json('example')->nullable();
            $table->json('buttons')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_components');
    }
};
