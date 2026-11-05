<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The other files of one output pack (ADR-053): one story image per approved
        // product, in snapshot order. The pack's PDF stays on its artifacts row; each
        // file here is encrypted under its own public id, so two files never swap.
        Schema::create('artifact_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('storage_key', 255)->unique();
            $table->char('content_mac', 64);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->unique(['artifact_id', 'position']);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_files');
    }
};
