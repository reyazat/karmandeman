<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FR-087 (ADR-014): the receipt outlives the purged workspace and user,
        // detached from both, so it must not cascade with them.
        Schema::table('account_deletions', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id']);
            $table->dropForeign(['user_id']);
        });
        Schema::table('account_deletions', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // Random Transit key name only, kept until key-store backups expire (ADR-013 open decision 3).
            $table->string('transit_key')->nullable();
            // Set before the irreversible destroy call: only then may a retry read "not found" as done.
            $table->timestamp('key_destroy_started_at')->nullable();
            $table->timestamp('key_destroyed_at')->nullable();
            $table->timestamp('live_purged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 128)->nullable();
        });

        // Keyed sender digest so a purge can find the account's events (ADR-014).
        Schema::table('conversation_events', function (Blueprint $table): void {
            $table->string('subject_key', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        // Detached receipts have no workspace or user left to point at, so the
        // NOT NULL cascade schema cannot return while they exist.
        if (DB::table('account_deletions')->whereNull('workspace_id')->orWhereNull('user_id')->exists()) {
            throw new RuntimeException('Detached erasure receipts exist; restore the pre-migration backup instead of rolling back.');
        }
        Schema::table('conversation_events', function (Blueprint $table): void {
            $table->dropIndex(['subject_key']);
            $table->dropColumn('subject_key');
        });
        Schema::table('account_deletions', function (Blueprint $table): void {
            $table->dropForeign(['workspace_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['transit_key', 'key_destroy_started_at', 'key_destroyed_at', 'live_purged_at', 'completed_at', 'attempts', 'last_error']);
        });
        Schema::table('account_deletions', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable(false)->change();
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
