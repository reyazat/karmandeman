<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Product Repository record, independent of its batch (PRD E06-a).
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->unsignedInteger('current_revision');
            $table->unsignedInteger('approved_revision')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'project_id']);
        });

        // Immutable revisions (ADR-016). The payload is private seller data,
        // encrypted under the workspace key; payload_mac binds an approval to
        // exactly this content without a plaintext-derived hash.
        Schema::create('product_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->text('payload');
            $table->char('payload_mac', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['product_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_revisions');
        Schema::dropIfExists('products');
    }
};
