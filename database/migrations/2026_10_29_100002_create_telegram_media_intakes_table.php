<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A photo sent to the bot, waiting for its server-side download (ADR-024).
        // The queued job carries only this row's id; the row is deleted when the
        // photo is stored or refused, by erasure, or by retention if it is stuck.
        Schema::create('telegram_media_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Bot API file_id, encrypted under the workspace key.
            $table->text('file_ref');
            $table->unsignedInteger('declared_size')->nullable();
            $table->char('message_key', 64)->unique();
            $table->timestamp('created_at');
        });

        Schema::table('assets', function (Blueprint $table): void {
            // Keyed digest of the Telegram chat and message (FR-030); null for API uploads.
            $table->char('source_message_key', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('source_message_key');
        });
        Schema::dropIfExists('telegram_media_intakes');
    }
};
