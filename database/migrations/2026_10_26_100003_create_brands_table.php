<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->string('currency_display_unit', 16)->default('toman');
            $table->string('tone')->nullable();
            $table->string('target_audience')->nullable();
            $table->text('brand_description')->nullable();
            $table->unsignedBigInteger('logo_asset_id')->nullable();
            $table->json('color_palette')->nullable();
            $table->json('contact_channels')->nullable();
            $table->boolean('catalog_price_visibility_default')->default(true);
            $table->json('default_outputs')->nullable();
            $table->json('required_product_fields')->nullable();
            $table->unsignedInteger('profile_version')->default(1);
            $table->timestamps();

            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
