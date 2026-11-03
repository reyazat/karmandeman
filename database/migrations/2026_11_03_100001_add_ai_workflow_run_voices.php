<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public ids of the batch voices a run may read, in order (ADR-047). Null on
        // runs made before voices joined runs: those read photos only.
        Schema::table('ai_workflow_runs', function (Blueprint $table): void {
            $table->json('input_voice_ids')->nullable()->after('input_asset_ids');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_runs', function (Blueprint $table): void {
            $table->dropColumn('input_voice_ids');
        });
    }
};
