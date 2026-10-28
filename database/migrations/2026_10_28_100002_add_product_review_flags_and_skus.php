<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-sensitive review flags of the current revision (FR-031, FR-040, ADR-017).
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('needs_confirmation')->default(false);
            $table->boolean('price_missing')->default(true);
            $table->boolean('image_missing')->default(true);
        });

        // HMAC of the normalized SKU under the workspace key; never the SKU itself.
        Schema::table('product_revisions', function (Blueprint $table): void {
            $table->char('sku_mac', 64)->nullable();
        });

        // SKUs held by the current and the approved revision of each product.
        Schema::create('product_skus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->char('sku_mac', 64);

            $table->unique(['workspace_id', 'sku_mac']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_skus');
        Schema::table('product_revisions', function (Blueprint $table): void {
            $table->dropColumn('sku_mac');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['needs_confirmation', 'price_missing', 'image_missing']);
        });
    }
};
