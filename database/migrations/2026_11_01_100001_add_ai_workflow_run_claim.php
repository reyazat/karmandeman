<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The one n8n execution allowed to work on a run (ADR-038). Only the
        // execution id n8n reported is kept; it names no person or content.
        Schema::table('ai_workflow_runs', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable()->after('dispatched_at');
            $table->string('claimed_by_execution', 64)->nullable()->after('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_runs', function (Blueprint $table): void {
            $table->dropColumn(['claimed_at', 'claimed_by_execution']);
        });
    }
};
