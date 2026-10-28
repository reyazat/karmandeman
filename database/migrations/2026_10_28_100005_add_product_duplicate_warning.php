<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Near-duplicate warning (FR-034, ADR-020): a keyed MAC of the name, never the name itself.
        Schema::table('products', function (Blueprint $table): void {
            $table->char('name_mac', 64)->nullable();
            $table->boolean('has_sku')->default(false);
            $table->boolean('duplicate_suspected')->default(false);

            $table->index(['workspace_id', 'name_mac']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'name_mac']);
            $table->dropColumn(['name_mac', 'has_sku', 'duplicate_suspected']);
        });
    }
};
