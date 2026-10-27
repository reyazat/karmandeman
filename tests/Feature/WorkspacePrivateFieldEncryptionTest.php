<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Workspace\RecordServiceRequestAction;
use App\Models\Brand;
use App\Models\MemoryItem;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Crypto\WorkspaceKeyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** NFR-11 stage S2 (ADR-013): private columns are ciphertext under the workspace's own key. */
final class WorkspacePrivateFieldEncryptionTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const PROFILE = [
        'name' => 'کیف آوا',
        'tone' => 'صمیمی',
        'target_audience' => 'دانشجویان تهران',
        'color_palette' => ['#112233'],
        'contact_channels' => ['@ava_bags'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
    }

    private function workspace(): Workspace
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner($user);
        $this->grantPilotConsent($user);
        Sanctum::actingAs($user);

        return $workspace;
    }

    private function saveProfile(string $key = 'encrypt-profile-1'): TestResponse
    {
        return $this->patchJson('/api/v1/brands/current', ['expected_version' => 1, ...self::PROFILE], ['Idempotency-Key' => $key]);
    }

    /** Next read starts a new request scope, so no unwrapped key is cached. */
    private function freshBrand(Workspace $workspace): Brand
    {
        $this->app->forgetScopedInstances();

        return Brand::query()->where('workspace_id', $workspace->id)->sole();
    }

    public function test_brand_memory_history_and_replay_snapshot_hold_no_plaintext(): void
    {
        $workspace = $this->workspace();
        $this->getJson('/api/v1/brands/current')->assertOk();
        $this->saveProfile()->assertOk()->assertJsonPath('data.name', 'کیف آوا');

        $stored = [
            ...array_values((array) DB::table('brands')->where('workspace_id', $workspace->id)
                ->first(['name', 'tone', 'target_audience', 'color_palette', 'contact_channels'])),
            ...DB::table('memory_items')->pluck('value_json')->all(),
            ...DB::table('memory_item_revisions')->pluck('value_json')->all(),
        ];
        $this->assertCount(15, $stored);
        foreach ($stored as $value) {
            $this->assertStringStartsWith('kmv1:', $value);
        }
        $snapshot = DB::table('api_idempotency_records')->where('operation', 'brand.update')->value('result_attributes');
        foreach (['کیف آوا', 'صمیمی', 'دانشجویان', '#112233', 'ava_bags'] as $plaintext) {
            $this->assertStringNotContainsString($plaintext, implode('|', $stored).$snapshot);
        }

        $this->app->forgetScopedInstances();
        $this->saveProfile()->assertOk()
            ->assertJsonPath('data.name', 'کیف آوا')
            ->assertJsonPath('data.color_palette', ['#112233'])
            ->assertJsonPath('data.profile_version', 2);
        $this->assertSame(['value' => 'صمیمی'], MemoryItem::query()->where('key', 'brand.tone')->sole()->value_json);
    }

    public function test_ciphertext_moved_to_another_workspace_or_legacy_plaintext_fails_closed(): void
    {
        $workspace = $this->workspace();
        $this->getJson('/api/v1/brands/current');
        $this->saveProfile()->assertOk();
        $other = $this->workspace();
        $this->getJson('/api/v1/brands/current')->assertOk();

        $moved = DB::table('brands')->where('workspace_id', $workspace->id)->value('name');
        DB::table('brands')->where('workspace_id', $other->id)->update(['name' => $moved, 'tone' => 'plain legacy']);

        foreach (['name', 'tone'] as $column) {
            try {
                $this->freshBrand($other)->getAttribute($column);
                $this->fail("brands.{$column} was readable outside its binding.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('کیف آوا', $this->freshBrand($workspace)->name);
    }

    public function test_destroyed_workspace_key_makes_its_data_unreadable_and_leaves_others_intact(): void
    {
        $workspace = $this->workspace();
        $this->getJson('/api/v1/brands/current');
        $this->saveProfile()->assertOk();
        $other = $this->workspace();
        $this->getJson('/api/v1/brands/current');
        $this->saveProfile('encrypt-profile-2')->assertOk();

        app(WorkspaceKeyManager::class)->destroy(app(WorkspaceFieldCipher::class)->keyName($workspace));

        try {
            $this->freshBrand($workspace)->name;
            $this->fail('Brand stayed readable after its key was destroyed.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('کیف آوا', $this->freshBrand($other)->name);
    }

    public function test_support_text_and_its_duplicate_hash_are_bound_to_the_workspace_key(): void
    {
        $record = app(RecordServiceRequestAction::class);
        $first = $this->workspace();
        $second = $this->workspace();
        $owner = fn (Workspace $workspace) => User::query()->findOrFail($workspace->owner_id);

        $a = $record->execute($first, $owner($first), 'support', 'support-key-a', 'مشکل در حساب');
        $again = $record->execute($first, $owner($first), 'support', 'support-key-b', 'مشکل در حساب');
        $b = $record->execute($second, $owner($second), 'support', 'support-key-c', 'مشکل در حساب');

        $this->assertSame($a->public_id, $again->public_id);
        $this->assertNotSame($a->details_hash, $b->details_hash);
        $this->assertStringStartsWith('kmv1:', (string) DB::table('service_requests')->where('id', $a->id)->value('details'));
        $this->app->forgetScopedInstances();
        $this->assertSame('مشکل در حساب', ServiceRequest::query()->findOrFail($b->id)->details);
    }
}
