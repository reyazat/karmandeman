<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PublicIdApiTest extends TestCase
{
    public function test_all_exposed_foundation_ids_are_opaque_and_distinct(): void
    {
        config(['karmandeman.gift_credits' => 10]);
        Sanctum::actingAs(app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(91901)));

        $ids = [
            $this->getJson('/api/v1/me')->assertOk()->json('data.id'),
            $this->getJson('/api/v1/workspaces/current')->assertOk()->json('data.id'),
            $this->getJson('/api/v1/brands/current')->assertOk()->json('data.id'),
            $this->getJson('/api/v1/wallet/ledger')->assertOk()->json('data.entries.0.id'),
            $this->postJson('/api/v1/projects', [], ['Idempotency-Key' => 'opaque-project-1'])
                ->assertCreated()->json('data.id'),
        ];

        foreach ($ids as $id) {
            $this->assertTrue(Str::isUuid($id));
        }
        $this->assertCount(5, array_unique($ids));
    }
}
