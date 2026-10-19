<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media for a message. We keep Meta's media id + a resolved S3 path; large
 * binaries live in object storage, not the DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('type', 32);              // image | document | video | audio | sticker
            $table->string('meta_media_id')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('storage_disk', 32)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('caption', 1024)->nullable();
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
