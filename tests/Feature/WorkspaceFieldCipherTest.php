<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Crypto\WorkspaceKeyManager;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fakes\InMemoryWorkspaceKeyManager;
use Tests\TestCase;

final class WorkspaceFieldCipherTest extends TestCase
{
    private InMemoryWorkspaceKeyManager $keys;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openbao.key_prefix' => 'ws-']);
        $this->keys = new InMemoryWorkspaceKeyManager;
        $this->app->instance(WorkspaceKeyManager::class, $this->keys);
    }

    private function workspace(): Workspace
    {
        return app(WorkspaceRepositoryInterface::class)->createForOwner(User::factory()->create());
    }

    private function freshCipher(): WorkspaceFieldCipher
    {
        $this->app->forgetScopedInstances();

        return app(WorkspaceFieldCipher::class);
    }

    public function test_round_trip_uses_one_wrapped_key_per_workspace_and_random_nonces(): void
    {
        $workspace = $this->workspace();
        $cipher = app(WorkspaceFieldCipher::class);

        $first = $cipher->encrypt($workspace, 'brands.name', 'کیف آوا');
        $second = $cipher->encrypt($workspace, 'brands.name', 'کیف آوا');

        $this->assertStringStartsWith('kmv1:', $first);
        $this->assertNotSame($first, $second);
        $this->assertStringNotContainsString('کیف', $first);
        $this->assertSame('کیف آوا', $this->freshCipher()->decrypt($workspace, 'brands.name', $first));
        $this->assertDatabaseCount('workspace_data_keys', 1);
        $row = DB::table('workspace_data_keys')->first();
        $this->assertSame('ws-'.$workspace->public_id, $row->transit_key);
        $this->assertStringStartsWith('fake:v1:', $row->wrapped_key);
        $this->assertSame(['ws-'.$workspace->public_id], array_keys($this->keys->keys));
    }

    public function test_unwrapped_key_is_reused_within_one_scope_only(): void
    {
        $workspace = $this->workspace();
        $value = app(WorkspaceFieldCipher::class)->encrypt($workspace, 'memory.value', 'x');
        $cipher = $this->freshCipher();
        $cipher->decrypt($workspace, 'memory.value', $value);
        $cipher->decrypt($workspace, 'memory.value', $value);

        $this->assertSame(1, $this->keys->unwrapCalls);
    }

    public function test_moved_tampered_or_foreign_ciphertext_does_not_decrypt(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace();
        $cipher = app(WorkspaceFieldCipher::class);
        $value = $cipher->encrypt($workspace, 'brands.name', 'secret');
        $cipher->encrypt($other, 'brands.name', 'other');
        $raw = base64_decode(substr($value, 5));
        $tampered = 'kmv1:'.base64_encode(substr($raw, 0, -1).chr(ord($raw[strlen($raw) - 1]) ^ 1));

        foreach ([
            fn () => $cipher->decrypt($workspace, 'brands.tone', $value),
            fn () => $cipher->decrypt($other, 'brands.name', $value),
            fn () => $cipher->decrypt($workspace, 'brands.name', $tampered),
            fn () => $cipher->decrypt($workspace, 'brands.name', 'plain text'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Ciphertext decrypted outside its binding.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_soft_deleted_or_destroyed_key_makes_data_unreadable_without_touching_other_tenants(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace();
        $cipher = app(WorkspaceFieldCipher::class);
        $value = $cipher->encrypt($workspace, 'brands.name', 'secret');
        $kept = $cipher->encrypt($other, 'brands.name', 'kept');

        $this->keys->softDelete($cipher->keyName($workspace));
        $this->expectUnreadable($workspace, $value);
        $this->keys->destroy($cipher->keyName($workspace));
        $this->expectUnreadable($workspace, $value);

        $this->assertSame('kept', $this->freshCipher()->decrypt($other, 'brands.name', $kept));
    }

    public function test_contained_workspace_never_gets_a_new_key(): void
    {
        $workspace = $this->workspace();
        app(WorkspaceRepositoryInterface::class)->markDeletionPending($workspace);

        $this->expectExceptionObject(new DomainException('ACCOUNT_DELETION_PENDING'));
        try {
            app(WorkspaceFieldCipher::class)->encrypt($workspace, 'brands.name', 'late');
        } finally {
            $this->assertSame([], $this->keys->keys);
            $this->assertDatabaseCount('workspace_data_keys', 0);
        }
    }

    public function test_key_created_in_a_rolled_back_transaction_is_never_reused(): void
    {
        $workspace = $this->workspace();
        $cipher = app(WorkspaceFieldCipher::class);
        try {
            DB::transaction(function () use ($cipher, $workspace): void {
                $cipher->encrypt($workspace, 'brands.name', 'lost');
                throw new DomainException('ROLLBACK');
            });
        } catch (DomainException) {
        }
        $this->assertDatabaseCount('workspace_data_keys', 0);

        // Same scope: a new key row is written, so later data stays decryptable.
        $value = $cipher->encrypt($workspace, 'brands.name', 'kept');
        $this->assertDatabaseCount('workspace_data_keys', 1);
        $this->assertSame('kept', $this->freshCipher()->decrypt($workspace, 'brands.name', $value));
    }

    public function test_mac_is_stable_per_workspace_and_differs_across_workspaces_and_contexts(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace();
        $cipher = app(WorkspaceFieldCipher::class);
        $mac = $cipher->mac($workspace, 'service_requests.details_hash', 'same');

        $this->assertSame($mac, $this->freshCipher()->mac($workspace, 'service_requests.details_hash', 'same'));
        $this->assertNotSame($mac, $cipher->mac($other, 'service_requests.details_hash', 'same'));
        $this->assertNotSame($mac, $cipher->mac($workspace, 'other.context', 'same'));
        $this->assertNotSame($mac, hash_hmac('sha256', 'same', (string) config('app.key')));
    }

    private function expectUnreadable(Workspace $workspace, string $value): void
    {
        try {
            $this->freshCipher()->decrypt($workspace, 'brands.name', $value);
            $this->fail('Data stayed readable after key removal.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
