<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // PostgreSQL builds the unique index CONCURRENTLY so webhook writes are not blocked.
    public $withinTransaction = false;

    public function up(): void
    {
        // Outside a transaction a failed run can stop half way; every step is rerunnable.
        if (! Schema::hasColumn('conversation_events', 'message_key')) {
            Schema::table('conversation_events', function (Blueprint $table): void {
                $table->string('message_key', 64)->nullable();
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            // A failed concurrent build leaves an INVALID index that IF NOT EXISTS would keep.
            $invalid = DB::selectOne("SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'conversation_events_message_key_unique' AND NOT i.indisvalid");
            if ($invalid !== null) {
                DB::statement('DROP INDEX CONCURRENTLY conversation_events_message_key_unique');
            }
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS conversation_events_message_key_unique ON conversation_events (message_key)');
        } else {
            Schema::table('conversation_events', fn (Blueprint $table) => $table->unique('message_key'));
        }
        if (! Schema::hasColumn('service_requests', 'details_hash')) {
            Schema::table('service_requests', function (Blueprint $table): void {
                $table->string('details_hash', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_requests', fn (Blueprint $table) => $table->dropColumn('details_hash'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversation_events_message_key_unique');
        } else {
            Schema::table('conversation_events', fn (Blueprint $table) => $table->dropUnique(['message_key']));
        }
        Schema::table('conversation_events', fn (Blueprint $table) => $table->dropColumn('message_key'));
    }
};
