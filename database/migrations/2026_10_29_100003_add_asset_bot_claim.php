<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The bot's /product attaches the batch photos not yet claimed by an
        // earlier /product (ADR-025). The product's own image list stays in its
        // encrypted revision; this column only marks what the bot handed out.
        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignId('claimed_by_product_id')->nullable()->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('claimed_by_product_id');
        });
    }
};
