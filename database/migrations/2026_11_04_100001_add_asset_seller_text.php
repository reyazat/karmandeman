<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The seller's own text about one photo (ADR-050): its caption, kept on the
        // queued intake until the photo is stored, and replies to that photo.
        // Encrypted under the workspace key; purge deletes both tables' rows.
        Schema::table('assets', function (Blueprint $table): void {
            $table->text('seller_text')->nullable()->after('source_message_key');
        });
        Schema::table('telegram_media_intakes', function (Blueprint $table): void {
            $table->text('seller_text')->nullable()->after('message_key');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('seller_text');
        });
        Schema::table('telegram_media_intakes', function (Blueprint $table): void {
            $table->dropColumn('seller_text');
        });
    }
};
