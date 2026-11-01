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
        // Held credit (ADR-039). Every ledger entry states its effect on both
        // caches: amount on the balance, held_amount on what is reserved. The
        // seller can spend balance - reserved; both caches equal their sums.
        Schema::table('wallets', function (Blueprint $table): void {
            $table->bigInteger('reserved_cached')->default(0)->after('balance_cached');
        });
        Schema::table('credit_ledger', function (Blueprint $table): void {
            $table->bigInteger('held_amount')->default(0)->after('amount');
        });
        if (DB::getDriverName() === 'pgsql') {
            // Last line of defence under the wallet lock: nothing reserves or spends credit it does not have.
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_credit_not_overdrawn CHECK (reserved_cached >= 0 AND balance_cached >= reserved_cached)');
        }

        // A priced offer the seller sees before paid work (FR-072, PRD §7.1).
        // Only counts and public photo ids: no product text or file content.
        Schema::create('credit_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('tariff_version', 32);
            // The batch version and photos priced; any change makes the quote stale.
            $table->unsignedInteger('project_version');
            $table->json('input_asset_ids');
            $table->json('lines');
            $table->unsignedInteger('credits_total');
            // Most the final charge may exceed the quote by; extraction never does.
            $table->unsignedInteger('increase_cap_credits')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        Schema::table('ai_workflow_runs', function (Blueprint $table): void {
            $table->foreignId('quote_id')->nullable()->after('requested_by')->constrained('credit_quotes')->nullOnDelete();
            $table->foreignId('reservation_entry_id')->nullable()->after('quote_id')->constrained('credit_ledger')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reservation_entry_id');
            $table->dropConstrainedForeignId('quote_id');
        });
        Schema::dropIfExists('credit_quotes');
        Schema::table('credit_ledger', function (Blueprint $table): void {
            $table->dropColumn('held_amount');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT IF EXISTS wallets_credit_not_overdrawn');
        }
        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn('reserved_cached');
        });
    }
};
