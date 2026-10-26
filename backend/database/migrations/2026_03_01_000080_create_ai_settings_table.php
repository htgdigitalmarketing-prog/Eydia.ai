<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform AI provider configuration. Singleton — one row, managed by the Super
 * Admin from Settings. Lets the admin pick ONE active provider (Gemini /
 * Anthropic / OpenAI), its API key and model, without editing .env. When no
 * active provider is set, the app falls back to the .env keys.
 *
 * API keys are encrypted at rest (model cast).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false); // master switch: use the DB config
            $table->string('active_provider', 20)->nullable(); // gemini | anthropic | openai

            $table->text('gemini_api_key')->nullable();
            $table->string('gemini_model')->nullable();
            $table->text('anthropic_api_key')->nullable();
            $table->string('anthropic_model')->nullable();
            $table->text('openai_api_key')->nullable();
            $table->string('openai_model')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
