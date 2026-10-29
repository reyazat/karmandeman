<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** Private, encrypted batch input files (FR-025, ADR-022). */
class AssetApiTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Storage::fake('assets');
    }

    private function seller(int $telegramId, bool $consent = true): User
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => random_int(1, 10_000_000),
            'message' => [
                'message_id' => 1, 'date' => time(), 'text' => '/start',
                'from' => ['id' => $telegramId, 'first_name' => 'U'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $user = User::query()->latest('id')->firstOrFail();
        if ($consent) {
            $this->grantPilotConsent($user);
        }

        return $user;
    }

    private function project(): string
    {
        return $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-'.++$this->keys])
            ->assertCreated()->json('data.id');
    }

    private function image(int $seed, string $type = 'jpeg', int $width = 40, int $height = 30): string
    {
        return ImageFixture::make($seed, $type, $width, $height);
    }

    private function upload(string $project, string $bytes, ?string $key = null, string $name = 'photo.jpg'): TestResponse
    {
        return $this->post("/api/v1/projects/{$project}/assets", [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Idempotency-Key' => $key ?? 'asset-key-'.++$this->keys, 'Accept' => 'application/json']);
    }

    private function version(string $project): int
    {
        return (int) $this->getJson("/api/v1/projects/{$project}")->assertOk()->json('data.version');
    }

    public function test_photo_is_stored_encrypted_and_downloaded_unchanged_by_its_owner(): void
    {
        Sanctum::actingAs($this->seller(950));
        $project = $this->project();
        $bytes = $this->image(1);

        $response = $this->upload($project, $bytes, 'asset-first')->assertCreated()
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.size_bytes', strlen($bytes))
            ->assertJsonPath('data.width', 40)
            ->assertJsonPath('data.height', 30)
            ->assertJsonPath('data.source', 'api_upload');
        $id = $response->json('data.id');
        $this->assertSame(['id', 'mime', 'size_bytes', 'width', 'height', 'source', 'created_at'], array_keys($response->json('data')));
        $this->assertSame(2, $this->version($project));

        // The object is ciphertext under a random key; the row holds no plain digest or name.
        $asset = Asset::query()->where('public_id', $id)->sole();
        $files = Storage::disk('assets')->allFiles();
        $this->assertSame([$asset->storage_key], $files);
        $this->assertStringStartsWith('ws/'.User::query()->latest('id')->firstOrFail()->workspaces()->value('public_id').'/', $asset->storage_key);
        $this->assertStringNotContainsString($id, $asset->storage_key);
        $stored = Storage::disk('assets')->get($asset->storage_key);
        $this->assertStringStartsWith('KMB1', $stored);
        $this->assertStringNotContainsString(substr($bytes, -40), $stored);
        $row = json_encode(DB::table('assets')->where('id', $asset->id)->first(), JSON_THROW_ON_ERROR);
        foreach ([hash('sha256', $bytes), md5($bytes), 'photo.jpg'] as $leak) {
            $this->assertStringNotContainsString($leak, $row);
        }

        $download = $this->get("/api/v1/assets/{$id}/download")->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$id.'.jpg"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertSame($bytes, $download->getContent());

        $this->getJson("/api/v1/projects/{$project}/assets")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        foreach (['png', 'webp'] as $i => $type) {
            $other = $this->image(10 + $i, $type);
            $created = $this->upload($project, $other, name: "x.{$type}")->assertCreated()->assertJsonPath('data.mime', "image/{$type}");
            $this->assertSame($other, $this->get('/api/v1/assets/'.$created->json('data.id').'/download')->assertOk()->getContent());
        }
    }

    public function test_same_photo_and_retries_reuse_the_first_asset(): void
    {
        Sanctum::actingAs($this->seller(951));
        $project = $this->project();
        $bytes = $this->image(2);
        $id = $this->upload($project, $bytes, 'asset-retry')->assertCreated()->json('data.id');

        $this->upload($project, $bytes, 'asset-retry')->assertOk()->assertJsonPath('data.id', $id);
        $this->upload($project, $bytes, 'asset-again')->assertOk()->assertJsonPath('data.id', $id);
        $this->upload($project, $this->image(3), 'asset-retry')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        $this->assertSame(1, Asset::query()->count());
        $this->assertCount(1, Storage::disk('assets')->allFiles());
        $this->assertSame(2, $this->version($project));

        // The same photo in another batch is a separate asset.
        $other = $this->project();
        $this->assertNotSame($id, $this->upload($other, $bytes)->assertCreated()->json('data.id'));
    }

    public function test_only_real_images_within_the_limits_are_accepted(): void
    {
        Sanctum::actingAs($this->seller(952));
        $project = $this->project();

        // The Action reads the bytes; the name and the client's type decide nothing.
        foreach (['not an image at all', $this->image(4, 'gif'), "<?php echo 1;\n".$this->image(5), '<svg xmlns="http://www.w3.org/2000/svg"/>'] as $bytes) {
            $this->upload($project, $bytes, name: 'x.jpg')->assertUnprocessable()->assertJsonPath('error.code', 'ASSET_UNSUPPORTED');
        }
        $this->upload($project, $this->image(4, 'gif'), name: 'x.gif')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->post("/api/v1/projects/{$project}/assets", [], ['Idempotency-Key' => 'asset-nofile', 'Accept' => 'application/json'])->assertUnprocessable();
        $this->post("/api/v1/projects/{$project}/assets", ['file' => UploadedFile::fake()->createWithContent('x.jpg', $this->image(6))], ['Accept' => 'application/json'])->assertUnprocessable();

        config(['karmandeman.assets.max_pixels' => 1000]);
        $this->upload($project, $this->image(7, width: 50, height: 30))->assertUnprocessable()->assertJsonPath('error.code', 'ASSET_TOO_LARGE');
        config(['karmandeman.assets.max_pixels' => 40_000_000, 'karmandeman.assets.max_kilobytes' => 1]);
        $this->upload($project, $this->image(8).str_repeat("\0", 2048))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, Asset::query()->count());
        $this->assertSame([], Storage::disk('assets')->allFiles());
        $this->assertSame(1, $this->version($project));
    }

    public function test_closed_batches_and_missing_consent_store_nothing(): void
    {
        Sanctum::actingAs($this->seller(953));
        $project = $this->project();
        $this->postJson("/api/v1/projects/{$project}/cancel", ['version' => 1], ['Idempotency-Key' => 'cancel-batch'])->assertOk();
        $this->upload($project, $this->image(9))->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_NOT_ACCEPTING_INPUT');

        Sanctum::actingAs($this->seller(954, consent: false));
        // Consent is checked before the project is even looked up.
        $this->upload('00000000-0000-4000-8000-000000000000', $this->image(9))
            ->assertForbidden()->assertJsonPath('error.code', 'POLICY_CONSENT_REQUIRED');

        $this->assertSame(0, Asset::query()->count());
        $this->assertSame([], Storage::disk('assets')->allFiles());
    }

    public function test_another_owner_cannot_list_upload_or_download(): void
    {
        Sanctum::actingAs($this->seller(955));
        $project = $this->project();
        $id = $this->upload($project, $this->image(11))->assertCreated()->json('data.id');

        Sanctum::actingAs($this->seller(956));
        $this->get("/api/v1/assets/{$id}/download", ['Accept' => 'application/json'])->assertNotFound()->assertJsonPath('error.code', 'ASSET_NOT_FOUND');
        $this->getJson("/api/v1/projects/{$project}/assets")->assertNotFound();
        $this->upload($project, $this->image(12))->assertNotFound()->assertJsonPath('error.code', 'PROJECT_NOT_FOUND');
        $this->get('/api/v1/assets/'.Asset::query()->sole()->id.'/download', ['Accept' => 'application/json'])->assertNotFound();

        $this->assertSame(1, Asset::query()->count());
    }

    public function test_swapped_edited_moved_or_missing_objects_are_refused(): void
    {
        Log::spy();
        Sanctum::actingAs($this->seller(957));
        $project = $this->project();
        $first = $this->upload($project, $this->image(13))->json('data.id');
        $second = $this->upload($project, $this->image(14))->json('data.id');
        [$a, $b] = [Asset::query()->where('public_id', $first)->sole(), Asset::query()->where('public_id', $second)->sole()];
        $refused = fn (string $id) => $this->get("/api/v1/assets/{$id}/download", ['Accept' => 'application/json'])
            ->assertStatus(500)->assertJsonPath('error.code', 'ASSET_INTEGRITY_FAILED');

        // Swap the two objects between rows of the same workspace.
        DB::table('assets')->where('id', $b->id)->update(['storage_key' => 'ws/swap']);
        DB::table('assets')->where('id', $a->id)->update(['storage_key' => $b->storage_key]);
        DB::table('assets')->where('id', $b->id)->update(['storage_key' => $a->storage_key]);
        $refused($first);
        $refused($second);
        DB::table('assets')->where('id', $b->id)->update(['storage_key' => 'ws/swap']);
        DB::table('assets')->where('id', $a->id)->update(['storage_key' => $a->storage_key]);
        DB::table('assets')->where('id', $b->id)->update(['storage_key' => $b->storage_key]);
        $this->get("/api/v1/assets/{$first}/download")->assertOk();

        // One flipped byte.
        $stored = Storage::disk('assets')->get($a->storage_key);
        Storage::disk('assets')->put($a->storage_key, substr($stored, 0, -1).chr(ord($stored[-1]) ^ 1));
        $refused($first);

        // A key outside the workspace prefix, and a missing object.
        Storage::disk('assets')->put('ws/elsewhere/x', $stored);
        DB::table('assets')->where('id', $b->id)->update(['storage_key' => 'ws/elsewhere/x']);
        $refused($second);
        DB::table('assets')->where('id', $b->id)->update(['storage_key' => $b->storage_key]);
        Storage::disk('assets')->delete($b->storage_key);
        $refused($second);

        Log::shouldHaveReceived('critical')->with('asset.integrity_failed', ['asset' => $first])->twice();
        Log::shouldHaveReceived('critical')->with('asset.integrity_failed', ['asset' => $second])->times(3);
    }

    public function test_storage_outage_is_not_tampering_and_production_needs_the_bucket(): void
    {
        Storage::fake('assets', ['throw' => false]);
        Log::spy();
        Sanctum::actingAs($this->seller(958));
        $project = $this->project();
        $id = $this->upload($project, $this->image(15))->assertCreated()->json('data.id');
        $path = Storage::disk('assets')->path(Asset::query()->sole()->storage_key);

        // An unreadable object on a non-throwing disk is an outage: plain 500, no integrity alarm.
        chmod($path, 0);
        try {
            $this->get("/api/v1/assets/{$id}/download", ['Accept' => 'application/json'])
                ->assertStatus(500)->assertJsonPath('error.code', 'REQUEST_FAILED');
        } finally {
            chmod($path, 0600);
        }
        Log::shouldNotHaveReceived('critical', ['asset.integrity_failed', ['asset' => $id]]);

        // Production refuses a local disk: nothing is written anywhere.
        $this->app['env'] = 'production';
        try {
            $this->upload($project, $this->image(16))->assertStatus(500);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame(1, Asset::query()->count());
        $this->assertCount(1, Storage::disk('assets')->allFiles());
    }
}
