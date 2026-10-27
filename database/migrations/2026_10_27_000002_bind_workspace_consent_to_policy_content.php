<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_consents', function (Blueprint $table): void {
            // Legacy preview receipts are not upgraded into final policy acceptance.
            $table->string('policy_document_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_consents', fn (Blueprint $table) => $table->dropColumn('policy_document_hash'));
    }
};
