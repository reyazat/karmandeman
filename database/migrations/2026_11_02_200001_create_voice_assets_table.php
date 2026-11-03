<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_key', 255)->unique();
            $table->char('content_mac', 64);
            $table->string('mime', 32);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('duration_ms');
            $table->string('source', 16);
            $table->string('source_message_key', 128)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'content_mac']);
            $table->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_assets');
    }
};
