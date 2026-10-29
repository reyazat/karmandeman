<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private batch input files (PRD FR-025, ADR-022). The object holds only
        // ciphertext under the workspace key; this row never holds a plain hash,
        // a file name or a storage URL.
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('storage_key', 255)->unique();
            // Keyed HMAC of the plaintext bytes: dedupe that dies with the key.
            $table->char('content_mac', 64);
            $table->string('mime', 32);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('source', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'content_mac']);
            $table->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
