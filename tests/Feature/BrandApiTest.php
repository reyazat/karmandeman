<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemoryItem;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandApiTest extends TestCase
{
    private function userWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceRepositoryInterface::class)
            ->createForOwner($user);
        Sanctum::actingAs($user);

        return [$user, $workspace];
    }

    public function test_brand_is_created_on_first_read_with_defaults(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        $response = $this->getJson('/api/v1/brands/current');

        $response->assertOk()
            ->assertJsonPath('data.currency_display_unit', 'toman')
            ->assertJsonPath('data.profile_version', 1);
    }

    public function test_brand_update_bumps_version_and_writes_memory(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();
        $this->getJson('/api/v1/brands/current');

        $response = $this->patchJson('/api/v1/brands/current', [
            'name' => 'کیف آوا',
            'tone' => 'صمیمی',
            'catalog_price_visibility_default' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'کیف آوا')
            ->assertJsonPath('data.profile_version', 2)
            ->assertJsonPath('data.catalog_price_visibility_default', false);

        $this->assertDatabaseHas('memory_items', [
            'workspace_id' => $workspace->id,
            'scope' => 'workspace_profile',
            'key' => 'brand.name',
            'verification_status' => 'confirmed',
            'confirmed_by' => $user->id,
        ]);
    }

    public function test_workspace_id_in_body_is_ignored(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();
        $other = Workspace::create(['owner_id' => User::factory()->create()->id, 'status' => 'active']);

        $this->patchJson('/api/v1/brands/current', [
            'name' => 'برند من',
            'workspace_id' => $other->id,
        ])->assertOk();

        $brand = app(BrandRepositoryInterface::class)->forWorkspace($workspace);
        $this->assertSame('برند من', $brand->name);
        $this->assertNull(app(BrandRepositoryInterface::class)->forWorkspace($other));
    }

    public function test_memory_put_creates_new_revision_not_duplicate(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        $this->patchJson('/api/v1/brands/current', ['name' => 'اول']);
        $this->patchJson('/api/v1/brands/current', ['name' => 'دوم']);

        $items = MemoryItem::where('workspace_id', $workspace->id)
            ->where('key', 'brand.name')->get();

        $this->assertCount(1, $items);
        $this->assertSame(2, $items->first()->revision);
        $this->assertSame('دوم', $items->first()->value_json['value']);
    }

    public function test_invalid_currency_unit_rejected(): void
    {
        $this->userWithWorkspace();

        $this->patchJson('/api/v1/brands/current', ['currency_display_unit' => 'usd'])
            ->assertUnprocessable();
    }
}
