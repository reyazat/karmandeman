<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MemoryRevisionMigrationTest extends TestCase
{
    public function test_migration_preserves_last_known_legacy_memory_revision(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.legacy_memory' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        try {
            DB::setDefaultConnection('legacy_memory');
            foreach (['users', 'workspaces'] as $table) {
                Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
                DB::table($table)->insert(['id' => 1]);
            }
            Schema::create('memory_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedInteger('revision');
                $table->json('value_json');
                $table->string('status');
                $table->string('verification_status');
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->timestamps();
            });
            DB::table('memory_items')->insert([
                'workspace_id' => 1, 'revision' => 4,
                'value_json' => json_encode(['value' => 'most recent only'], JSON_THROW_ON_ERROR),
                'status' => 'active', 'verification_status' => 'confirmed',
                'confirmed_by' => 1, 'source_type' => 'brand_profile', 'source_id' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $migration = require database_path('migrations/2026_10_26_120004_create_telegram_onboarding_tables.php');
            $migration->up();
            $revision = DB::table('memory_item_revisions')->sole();
            $this->assertSame(4, $revision->revision);
            $this->assertSame('most recent only', json_decode($revision->value_json, true)['value']);
            $this->assertSame(1, DB::table('memory_item_revisions')->count());
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('legacy_memory');
        }
    }
}
