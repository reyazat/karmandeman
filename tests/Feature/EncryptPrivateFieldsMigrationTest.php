<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;
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
}
