<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** The S2 migration never rewrites data: legacy plaintext blocks it and ciphertext blocks rollback. */
final class EncryptPrivateFieldsMigrationTest extends TestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_10_27_000005_encrypt_workspace_private_fields.php');
    }

    private function brandRow(string $name): void
    {
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner(User::factory()->create());
        DB::table('brands')->insert([
            'workspace_id' => $workspace->id, 'public_id' => (string) str()->uuid(), 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_legacy_plaintext_blocks_the_migration(): void
    {
        $this->brandRow('Legacy plaintext brand');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('brands.name has 1 plaintext or APP_KEY value(s)');
        $this->migration()->up();
    }

    public function test_workspace_ciphertext_blocks_rollback(): void
    {
        $this->brandRow('kmv1:c2ltdWxhdGVkLWNpcGhlcnRleHQ=');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('brands.name holds workspace ciphertext');
        $this->migration()->down();
    }

    public function test_round_trip_keeps_original_nullability(): void
    {
        // Revoked revisions may hold NULL; only memory_items.value_json was NOT NULL.
        $this->assertNullability();
        $migration = $this->migration();
        $migration->down();
        $this->assertNullability();
        $migration->up();
        $this->assertNullability();
    }

    private function assertNullability(): void
    {
        $nullable = fn (string $table): bool => collect(Schema::getColumns($table))->firstWhere('name', 'value_json')['nullable'];
        $this->assertFalse($nullable('memory_items'));
        $this->assertTrue($nullable('memory_item_revisions'));
    }
}
