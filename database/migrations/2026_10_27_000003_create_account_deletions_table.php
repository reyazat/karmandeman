<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One erasure lifecycle per workspace (FR-086, ADR-011). Legacy
        // service_requests of kind data_deletion are tickets, not promoted here.
        Schema::create('account_deletions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 32)->default('requested')->index();
            $table->timestamp('requested_at');
            $table->timestamp('deadline_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Workspaces already marked deletion_pending stay contained; restore them
        // only through a reviewed operator decision, never by this rollback.
        Schema::dropIfExists('account_deletions');
    }
};
