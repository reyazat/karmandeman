<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicIdMigrationTest extends TestCase
{
    public function test_legacy_rows_and_idempotency_snapshots_are_backfilled_without_changing_internal_ids(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.legacy_public_ids' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        try {
            DB::setDefaultConnection('legacy_public_ids');
            foreach (['users', 'workspaces', 'brands', 'projects', 'credit_ledger'] as $table) {
                Schema::create($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->id();
                    if (in_array($table, ['brands', 'projects'], true)) {
                        $blueprint->unsignedBigInteger('workspace_id');
                    }
                });
                DB::table($table)->insert($table === 'brands' || $table === 'projects'
                    ? ['id' => 17, 'workspace_id' => 5]
                    : ['id' => 5]);
            }
            Schema::create('api_idempotency_records', function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->unsignedBigInteger('workspace_id');
                $blueprint->string('operation');
                $blueprint->json('result_attributes');
            });
            foreach (['project.create', 'brand.update'] as $operation) {
                DB::table('api_idempotency_records')->insert([
                    'workspace_id' => 5, 'operation' => $operation,
                    'result_attributes' => json_encode(['id' => 17], JSON_THROW_ON_ERROR),
                ]);
            }

            $migration = require database_path('migrations/2026_10_26_120002_add_public_ids.php');
            $migration->up();

            foreach (['users', 'workspaces', 'brands', 'projects', 'credit_ledger'] as $table) {
                $this->assertTrue(Str::isUuid(DB::table($table)->value('public_id')));
            }
            foreach (DB::table('api_idempotency_records')->get() as $record) {
                $table = $record->operation === 'brand.update' ? 'brands' : 'projects';
                $snapshot = json_decode($record->result_attributes, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(17, $snapshot['id']);
                $this->assertSame(DB::table($table)->value('public_id'), $snapshot['public_id']);
            }
            $this->assertSame(17, DB::table('projects')->value('id'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('legacy_public_ids');
        }
    }
}
