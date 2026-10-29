<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Project\ImportTelegramPhotoAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Project\ImportTelegramPhoto;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Asset;
use App\Models\Product;
use App\Models\Project;
use App\Models\TelegramMediaIntake;
use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** `/product` turns typed text and the batch's new photos into a draft (ADR-025). */
class TelegramProductCommandTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private int $updates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
        ]);
        Storage::fake('assets');
        Queue::fake([SendTelegramMessage::class]);
        // File "f{n}" is a PNG made from seed n.
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/getFile')
            ? Http::response(['ok' => true, 'result' => ['file_path' => 'p/'.$request['file_id'], 'file_size' => 100]])
            : Http::response(ImageFixture::make((int) substr((string) strrchr($request->url(), 'f'), 1), 'png', 8, 8)));
    }

    private function send(array $message, int $telegramId, ?int $updateId = null): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', [
            'update_id' => $updateId ?? ++$this->updates,
            'message' => $message + [
                'message_id' => ++$this->updates, 'date' => time(),
                'from' => ['id' => $telegramId, 'first_name' => 'U'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function seller(int $telegramId, string $unit = 'toman', bool $project = true): User
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        $onboarding->execute($workspace, $user, 'input', 'Brand');
        $onboarding->execute($workspace, $user, 'unit', $unit);
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'confirm');
        if ($project) {
            $this->send(['text' => '/new Batch'], $telegramId)->assertOk();
        }

        return $user;
    }

    private function photos(int $telegramId, int ...$seeds): void
    {
        foreach ($seeds as $seed) {
            $this->send(['document' => ['file_id' => "f{$seed}", 'mime_type' => 'image/png', 'file_size' => 100]], $telegramId)->assertOk();
        }
    }

    private function replied(string $fragment): void
    {
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, $fragment));
    }

    /** @return array<string, mixed> */
    private function record(User $user, Product $product): array
    {
        Sanctum::actingAs($user);

        $data = $this->getJson("/api/v1/products/{$product->public_id}")->assertOk()->json('data');

        return $data['current'] + ['review' => $data['review']];
    }

    public function test_product_takes_the_typed_name_price_and_the_new_photos_only(): void
    {
        $user = $this->seller(990);
        $this->photos(990, 1, 2);

        $this->send(['text' => "/product کیف  چرم مشكي\r\n۱۲۰٬۰۰۰\u{200C} تومان"], 990)->assertOk();
        $this->replied('با 2 عکس و قیمت 120,000 تومان');
        $first = Product::query()->sole();
        $ids = Asset::query()->orderBy('id')->pluck('public_id')->all();
        $record = $this->record($user, $first);
        $this->assertSame('کیف چرم مشکی', $record['name']['value']);
        $this->assertSame(['name.value' => 'کیف  چرم مشكي'], $record['originals']);
        $this->assertSame('confirmed', $record['name']['status']);
        $this->assertSame(1_200_000, $record['pricing']['regular_price_irr']);
        $this->assertSame('toman', $record['pricing']['display_unit']);
        $this->assertSame($ids, $record['image_asset_ids']);
        $this->assertFalse($record['review']['image_missing']);

        // The next product gets only photos sent after the first one, and no price.
        $this->photos(990, 3);
        $this->send(['text' => '/product کفش'], 990)->assertOk();
        $second = Product::query()->latest('id')->first();
        $record = $this->record($user, $second);
        $this->assertSame([Asset::query()->latest('id')->value('public_id')], $record['image_asset_ids']);
        $this->assertNull($record['pricing']['regular_price_irr']);
        $this->assertTrue($record['review']['price_missing']);

        $this->send(['text' => "/product کلاه\n٥٠٬٠٠٠ ریال"], 990)->assertOk();
        $record = $this->record($user, Product::query()->latest('id')->first());
        $this->assertSame([], $record['image_asset_ids']);
        $this->assertSame(50_000, $record['pricing']['regular_price_irr']);
    }

    public function test_a_price_needs_its_unit_and_a_repeated_update_creates_one_product(): void
    {
        $user = $this->seller(991, unit: 'irr');
        // The brand unit is never assumed (PRD E04).
        $this->send(['text' => "/product شال\n90000"], 991)->assertOk();
        $this->replied('واحد قیمت را بنویس');
        $this->assertSame(0, Product::query()->count());

        $this->send(['text' => "/product شال\n90,000 ریال"], 991, 70_001)->assertOk();
        $this->send(['text' => "/product شال\n90,000 ریال"], 991, 70_001)->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(90_000, $this->record($user, Product::query()->sole())['pricing']['regular_price_irr']);
    }

    public function test_photos_still_importing_block_the_product_and_extra_photos_wait(): void
    {
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
        $user = $this->seller(993);
        $this->photos(993, 1);
        $this->send(['text' => '/product کیف'], 993)->assertOk();
        $this->replied('در حال دریافت');
        $this->assertSame(0, Product::query()->count());

        app(ImportTelegramPhotoAction::class)->execute(TelegramMediaIntake::query()->value('id'));
        foreach (range(2, 11) as $seed) {
            $this->photos(993, $seed);
            app(ImportTelegramPhotoAction::class)->execute(TelegramMediaIntake::query()->value('id'));
        }
        $this->send(['text' => '/product کیف'], 993)->assertOk();
        $this->replied('بیشتر از ۱۰');
        $record = $this->record($user, Product::query()->sole());
        $this->assertSame(Asset::query()->orderBy('id')->limit(10)->pluck('public_id')->all(), $record['image_asset_ids']);
        $this->assertSame(1, Asset::query()->whereNull('claimed_by_product_id')->count());
    }

    public function test_malformed_text_and_closed_batches_create_nothing(): void
    {
        $this->seller(992, project: false);
        $this->send(['text' => '/product کیف'], 992)->assertOk();
        $this->replied('/new');

        $this->send(['text' => '/new Batch'], 992)->assertOk();
        foreach (['/product', "/product کیف\nصد هزار تومان", "/product کیف\n0 تومان", "/product الف\nب\nپ", '/product '.str_repeat('ا', 201),
            "/product کیف\n1234567890123 ریال", "/product کیف\n12.5 تومان", "/product کیف\n1,5 تومان", "/product کیف\n۱۲٫۵ تومان", "/product کیف\n-500 تومان"] as $text) {
            $this->send(['text' => $text], 992)->assertOk();
        }
        $this->replied('خط اول');
        $this->replied('عدد صحیح');
        $this->replied('۲۰۰ نویسه');
        $this->replied('قیمت معتبر نیست');

        // A batch past input takes no bot product; the seller is sent back to /new.
        Queue::fake([SendTelegramMessage::class]);
        Project::query()->update(['state' => 'review_required']);
        $this->send(['text' => "/product کیف\n۱۰۰ تومان"], 992)->assertOk();
        $this->replied('/new');
        $this->assertSame(0, Product::query()->count());
    }
}
