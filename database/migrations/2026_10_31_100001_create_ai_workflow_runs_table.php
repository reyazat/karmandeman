<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One n8n product extraction run of a batch (ADR-032). The public id is
        // the run_id both sides correlate on; n8n never sees the internal ids.
        // No product text is stored here: candidates become product revisions.
        Schema::create('ai_workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('workflow', 64);
            $table->string('workflow_version', 64);
            $table->string('status', 32);
            // Public ids of the batch photos sent, in order; the only assets a candidate may cite.
            $table->json('input_asset_ids');
            $table->unsignedSmallInteger('dispatch_attempts')->default(0);
            $table->timestamp('deadline_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            // Workspace-keyed MAC of the accepted callback body: a retry of it is answered, a different one refused.
            $table->char('result_mac', 64)->nullable();
            $table->unsignedSmallInteger('candidate_count')->nullable();
            // Reported provider usage (PRD §11 cost control); counts only, no content.
            $table->json('usage')->nullable();
            $table->timestamps();

            $table->index(['status', 'deadline_at']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workflow_runs');
    }
};
