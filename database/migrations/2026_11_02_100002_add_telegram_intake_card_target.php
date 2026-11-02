<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A photo sent in reply to a product card joins that product (ADR-041):
        // the card's product and the revision it showed, nothing about the chat.
        Schema::table('telegram_media_intakes', function (Blueprint $table): void {
            $table->foreignId('target_product_id')->nullable()->after('kind')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('target_revision')->nullable()->after('target_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_media_intakes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_product_id');
            $table->dropColumn('target_revision');
        });
    }
};
