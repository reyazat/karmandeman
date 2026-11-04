<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One generated output file of one approved snapshot (ADR-052). The bytes live
        // encrypted in private storage; this row holds where, its state and the hold
        // that pays for it. One row per snapshot and kind: asking again reuses it.
        Schema::create('artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('storage_key', 255)->nullable()->unique();
            $table->char('content_mac', 64)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedInteger('price_credits');
            $table->foreignId('hold_entry_id')->nullable()->constrained('credit_ledger')->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['project_snapshot_id', 'kind']);
            $table->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
