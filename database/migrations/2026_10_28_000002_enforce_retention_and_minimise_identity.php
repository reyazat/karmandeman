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
        // ADR-015: an expired snapshot keeps its key and fingerprint but no response.
        Schema::table('api_idempotency_records', function (Blueprint $table): void {
            $table->json('result_attributes')->nullable()->change();
        });

        // Telegram display name, username and language were stored but never read.
        // Irreversible: back up before running on any database with real accounts.
        DB::table('users')->whereNotNull('name')->update(['name' => null]);
        DB::table('user_identities')->whereNotNull('meta')->update(['meta' => null]);
        DB::table('workspaces')->whereNotNull('name')->update(['name' => null]);
    }

    public function down(): void
    {
        // Erased names cannot come back; only the snapshot column constraint is restored.
        if (DB::table('api_idempotency_records')->whereNull('result_attributes')->exists()) {
            throw new RuntimeException('Expired idempotency snapshots exist; restore the pre-migration backup instead of rolling back.');
        }
        Schema::table('api_idempotency_records', function (Blueprint $table): void {
            $table->json('result_attributes')->nullable(false)->change();
        });
    }
};
