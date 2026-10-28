<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gate A binds the approval to its product, revision and content (ADR-019).
        Schema::table('product_revisions', function (Blueprint $table): void {
            $table->char('approval_mac', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_revisions', function (Blueprint $table): void {
            $table->dropColumn('approval_mac');
        });
    }
};
