<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Brand\EnsureBrandAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Privacy\ConfirmTelegramAccountDeletionAction;
use App\Actions\Privacy\FindContainedTelegramAccountAction;
use App\Actions\Privacy\PrepareTelegramAccountDeletionAction;
use App\Actions\Privacy\RequestAccountDeletionAction;
use App\Actions\Product\CreateTelegramProductDraftAction;
use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\QueueTelegramPhotoAction;
use App\Actions\Workspace\RecordServiceRequestAction;
use App\Data\Privacy\ContainedAccount;
use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Privacy\PilotPolicyService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Routes inbound Telegram updates to intents. Channel adapter only:
 * domain state lives in Actions/Repositories.
 */
final readonly class TelegramConversationService
{
    private const CALLBACK_PATTERN = '/^(onboard:(accept:[a-zA-Z0-9_-]{1,64}|unit:(toman|irr)|skip:(category|tone)|confirm:[0-9]+:[a-f0-9]{16}|edit)|delete:(confirm:[0-9]{1,12}:[a-f0-9]{16}|cancel))$/D';

    public function __construct(
        private RegisterTelegramUserAction $register,
        private WorkspaceRepositoryInterface $workspaces,
        private WalletRepositoryInterface $wallets,
        private ProjectRepositoryInterface $projects,
        private EnsureBrandAction $ensureBrand,
        private AdvanceTelegramOnboardingAction $onboarding,
        private CreateProjectAction $createProject,
        private QueueTelegramPhotoAction $queuePhoto,
        private CreateTelegramProductDraftAction $createProduct,
        private TelegramSubjectKey $subjects,
        private RecordServiceRequestAction $serviceRequests,
        private TelegramReplyService $bot,
        private PilotPolicyService $policies,
        private FindContainedTelegramAccountAction $containedAccounts,
        private PrepareTelegramAccountDeletionAction $prepareDeletion,
        private ConfirmTelegramAccountDeletionAction $confirmDeletion,
    ) {}

    public function handleUpdate(array $update): void
    {
        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $this->handleCallback($callback);

            return;
        }
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        if (($message['chat']['type'] ?? null) !== 'private'
            || ($message['chat']['id'] ?? null) !== ($message['from']['id'] ?? null)) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === null) {
            return;
        }
        // From T0 nothing may register, grant or mutate for this account (FR-086).
        $contained = $this->containedAccounts->execute($message['from']['id']);
        if ($contained !== null) {
            $this->bot->sendMessage($chatId, $this->containedNotice($contained));

            return;
        }

        // A caption is ignored: the photo is batch input, not a command (ADR-024).
        if (isset($message['photo']) || isset($message['document'])) {
            $this->handlePhoto($message, $chatId);

            return;
        }
        if ($text === '') {
            $this->replyOnboarding($message, $chatId, 'prompt');

            return;
        }
        $command = strtok($text, " \r\n");

        match ($command) {
            '/start' => $this->handleStart($message, $chatId),
            '/help' => $this->bot->sendMessage($chatId, $this->helpText()),
            '/balance' => $this->handleBalance($message, $chatId),
            '/new' => $this->handleNew($message, $chatId, $text),
            '/projects' => $this->handleProjects($message, $chatId),
            '/product' => $this->handleProduct($message, $chatId, trim(mb_substr($text, mb_strlen('/product')))),
            '/mybrand' => $this->handleMyBrand($message, $chatId),
            '/brand' => $this->replyOnboarding($message, $chatId, 'edit'),
            '/skip' => $this->replyOnboarding($message, $chatId, 'skip'),
            '/confirm' => $this->replyOnboarding($message, $chatId, 'confirm'),
            '/forget' => $this->replyOnboarding($message, $chatId, 'forget', trim(mb_substr($text, mb_strlen('/forget')))),
            '/terms' => $this->handlePolicyDocument($chatId, 'terms'),
            '/privacy' => $this->handlePolicyDocument($chatId, 'privacy'),
            '/retention' => $this->handlePolicyDocument($chatId, 'retention'),
            '/support' => $this->handleSupport($message, $chatId, trim(mb_substr($text, mb_strlen('/support')))),
            '/delete_data' => $this->promptDeletion($message, $chatId),
            '/ticket' => $this->handleTicket($message, $chatId, trim(mb_substr($text, mb_strlen('/ticket')))),
            default => str_starts_with($text, '/')
                ? $this->bot->sendMessage($chatId, 'دستور شناخته نشد؛ /help را بفرستید.')
                : $this->replyOnboarding($message, $chatId, 'input', $text),
        };
    }

    private function handleStart(array $message, int|string $chatId): void
    {
        $this->replyOnboarding($message, $chatId, 'prompt');
    }

    private function handlePolicyDocument(int|string $chatId, string $document): void
    {
        $policy = $this->policies->current();
        if (! $policy->isApproved()) {
            $this->bot->sendMessage($chatId, $policy->unavailableNotice());

            return;
        }
        $text = match ($document) {
            'terms' => $policy->terms,
            'privacy' => $policy->privacy,
            'retention' => $policy->retention,
            default => throw new \InvalidArgumentException('Unknown policy document.'),
        };
        $text = "نسخه: {$policy->version}\nشناسه متن: {$policy->digest}\n\n".$text;
        foreach (mb_str_split($text, 3000) as $part) {
            $this->bot->sendMessage($chatId, $part);
        }
    }

    private function handleBalance(array $message, int|string $chatId): void
    {
        $user = $this->resolveUser($message);
        $wallet = $this->wallets->forWorkspace($this->workspaceFor($user));
        $balance = $wallet === null ? 0 : $wallet->balance_cached;

        $this->bot->sendMessage($chatId, "موجودی اعتبار تو: {$balance} اعتبار.");
    }

    private function handleNew(array $message, int|string $chatId, string $text): void
    {
        $user = $this->resolveUser($message);
        if (! $this->readyForProject($user, $chatId)) {
            return;
        }
        $name = trim(mb_substr($text, mb_strlen('/new'))) ?: null;
        if ($name !== null && mb_strlen($name) > 255) {
            $this->bot->sendMessage($chatId, 'نام پروژه باید حداکثر ۲۵۵ نویسه باشد.');

            return;
        }
        $project = $this->createProject->execute($this->workspaceFor($user), $name);

        $this->bot->sendMessage(
            $chatId,
            "پروژه «{$project->name}» ساخته شد.\n".
            'عکس محصولات این پروژه را همین‌جا بفرست (JPEG، PNG یا WebP، حداکثر ۱۰ مگابایت). پردازش خودکار هنوز فعال نیست.'
        );
    }

    private function handlePhoto(array $message, int|string $chatId): void
    {
        $user = $this->resolveUser($message);
        if (! $this->readyForProject($user, $chatId)) {
            return;
        }
        $reply = $this->queuePhoto->execute($this->workspaceFor($user), $user, $message);
        if ($reply !== null) {
            $this->bot->sendMessage($chatId, $reply);
        }
    }

    private function handleProduct(array $message, int|string $chatId, string $input): void
    {
        $user = $this->resolveUser($message);
        if (! $this->readyForProject($user, $chatId)) {
            return;
        }
        $this->bot->sendMessage($chatId, $this->createProduct->execute(
            $this->workspaceFor($user), $user, $this->subjects->message($chatId, $message['message_id'] ?? Str::uuid()->toString()), $input,
        ));
    }

    private function handleProjects(array $message, int|string $chatId): void
    {
        $user = $this->resolveUser($message);
        if (! $this->readyForProject($user, $chatId)) {
            return;
        }
        $projects = $this->projects->paginateFor($this->workspaceFor($user), 5);

        if ($projects->isEmpty()) {
            $this->bot->sendMessage($chatId, 'هنوز پروژه‌ای نداری. با /new یکی بساز.');

            return;
        }

        $lines = collect($projects->items())->map(
            fn ($p) => "• {$p->name} — {$p->state->value}"
        );

        $this->bot->sendMessage($chatId, "آخرین پروژه‌ها:\n".$lines->implode("\n"));
    }

    private function handleMyBrand(array $message, int|string $chatId): void
    {
        $user = $this->resolveUser($message);
        $workspace = $this->workspaceFor($user);
        if (! $this->onboarding->isReady($workspace)) {
            $status = $this->onboarding->execute($workspace, $user);
            $this->bot->sendMessage($chatId, $status->text, $status->buttons);

            return;
        }
        $brand = $this->ensureBrand->execute($workspace);

        $name = $brand->name ?? '—';
        $unit = $brand->currency_display_unit === 'irr' ? 'ریال' : 'تومان';

        $this->bot->sendMessage(
            $chatId,
            "برند: {$name}\nواحد قیمت: {$unit}\nلحن: ".($brand->tone ?? '—')
        );
    }

    private function handleCallback(array $callback): void
    {
        // Always ACK so stale or foreign buttons stop spinning and never trigger retries.
        $this->bot->acknowledge($callback['id']);
        $from = $callback['from'];
        $chat = $callback['message']['chat'] ?? [];
        $data = (string) ($callback['data'] ?? '');
        if (($chat['type'] ?? null) !== 'private' || ($chat['id'] ?? null) !== $from['id']
            || preg_match(self::CALLBACK_PATTERN, $data) !== 1) {
            return;
        }
        $contained = $this->containedAccounts->execute($from['id']);
        if ($contained !== null) {
            $this->bot->sendMessage($chat['id'], $this->containedNotice($contained));
        } elseif (str_starts_with($data, 'delete:')) {
            $this->handleDeletionCallback($from['id'], $chat['id'], $data);
        } else {
            $this->handleOnboardingCallback($from, $chat, $data);
        }
    }

    private function handleOnboardingCallback(array $from, array $chat, string $callbackData): void
    {
        $data = explode(':', $callbackData);
        $event = $data[1] ?? '';
        $value = match ($event) {
            'accept', 'unit', 'skip' => $data[2] ?? null,
            'confirm' => isset($data[2], $data[3]) ? "{$data[2]}:{$data[3]}" : null,
            default => null,
        };
        $this->replyOnboarding(['from' => $from, 'chat' => $chat], $chat['id'], $event, $value);
    }

    private function promptDeletion(array $message, int|string $chatId): void
    {
        $confirm = $this->prepareDeletion->execute($message['from']['id']);
        if ($confirm === null) {
            $this->bot->sendMessage($chatId, 'حسابی با این تلگرام ثبت نشده است؛ داده حسابی برای حذف وجود ندارد.');

            return;
        }
        $this->bot->sendMessage(
            $chatId,
            "حذف کامل داده‌ها و حساب\n".
            'با تأیید، از همین لحظه حساب مسدود می‌شود: هیچ تغییر یا پردازش تازه‌ای انجام نمی‌شود و ورودهای Mini App باطل می‌شوند. '.
            'حساب، برند، حافظه و تاریخچه، پروژه‌ها، رسیدها و نسخه‌های پشتیبان باید حداکثر '.RequestAccountDeletionAction::DEADLINE_HOURS.' ساعت (۷ روز) پس از تأیید پاک شوند. '.
            "این کار برگشت‌ناپذیر است و تا اثبات تکمیل، وضعیت «در حال رسیدگی» نمایش داده می‌شود.\n".
            'این دکمه ۱۰ دقیقه معتبر است.',
            [
                [['text' => 'بله، همه داده‌ها و حساب حذف شود', 'callback_data' => $confirm]],
                [['text' => 'انصراف', 'callback_data' => 'delete:cancel']],
            ],
        );
    }

    private function handleDeletionCallback(int $telegramId, int|string $chatId, string $callbackData): void
    {
        if ($callbackData === 'delete:cancel') {
            $this->bot->sendMessage($chatId, 'درخواست حذف ثبت نشد و حساب بدون تغییر ماند.');

            return;
        }
        $deletion = $this->confirmDeletion->execute($telegramId, $callbackData);
        $this->bot->sendMessage($chatId, $deletion === null
            ? 'این تأیید معتبر یا تازه نیست؛ برای حذف دوباره /delete_data را بفرستید.'
            : "درخواست حذف کامل ثبت شد.\n".$this->containedNotice(new ContainedAccount($deletion)));
    }

    private function containedNotice(ContainedAccount $account): string
    {
        $deletion = $account->deletion;
        if ($deletion === null) {
            return 'این حساب فعال نیست و دستوری برای آن انجام نمی‌شود.';
        }

        return "رسید حذف: {$deletion->public_id}\n".
            'زمان ثبت (UTC): '.$deletion->requested_at->copy()->utc()->format('Y-m-d H:i')."\n".
            'مهلت تکمیل (UTC): '.$deletion->deadline_at->copy()->utc()->format('Y-m-d H:i')."\n".
            'وضعیت: در حال رسیدگی. تا پایان حذف، دستور دیگری برای این حساب انجام نمی‌شود.';
    }

    private function replyOnboarding(array $message, int|string $chatId, string $event, ?string $value = null): void
    {
        $user = $this->resolveUser($message);
        $reply = $this->onboarding->execute($this->workspaceFor($user), $user, $event, $value);
        $this->bot->sendMessage($chatId, $reply->text, $reply->buttons);
    }

    private function readyForProject(User $user, int|string $chatId): bool
    {
        $workspace = $this->workspaceFor($user);
        if (! $this->onboarding->isReady($workspace)) {
            $reply = $this->onboarding->execute($workspace, $user);
            $this->bot->sendMessage($chatId, $reply->text, $reply->buttons);

            return false;
        }

        return true;
    }

    private function handleSupport(array $message, int|string $chatId, string $details): void
    {
        if ($details === '') {
            $this->bot->sendMessage($chatId, 'برای پشتیبانی، /support و سپس شرح کوتاه مشکل را بفرستید.');

            return;
        }
        if (mb_strlen($details) > 500) {
            $this->bot->sendMessage($chatId, 'شرح درخواست باید حداکثر ۵۰۰ نویسه باشد.');

            return;
        }
        $user = $this->resolveUser($message);
        // message_id never repeats within a chat, unlike update_id after a week of inactivity.
        // Without message_id the open-ticket check still dedupes; never hash the private text itself.
        $source = 'message:'.($message['message_id'] ?? Str::uuid()->toString());
        $key = 'telegram:'.substr(hash('sha256', $chatId.':'.$source), 0, 48);
        $ticket = $this->serviceRequests->execute($this->workspaceFor($user), $user, 'support', $key, $details);
        $this->bot->sendMessage($chatId, "درخواست پشتیبانی ثبت شد؛ رسید: {$ticket->public_id}\nبرای وضعیت /ticket {$ticket->public_id} را بفرستید.");
    }

    private function handleTicket(array $message, int|string $chatId, string $publicId): void
    {
        $user = $this->resolveUser($message);
        $ticket = Str::isUuid($publicId)
            ? $this->serviceRequests->findFor($this->workspaceFor($user), $publicId)
            : null;
        $this->bot->sendMessage($chatId, $ticket === null
            ? 'رسید یافت نشد.'
            : "رسید {$ticket->public_id}: وضعیت {$ticket->status}.");
    }

    private function resolveUser(array $message): User
    {
        return $this->register->execute(
            TelegramUserData::fromArray($message['from'] ?? [])
        );
    }

    private function workspaceFor(User $user): Workspace
    {
        $workspace = $this->workspaces->currentFor($user);
        abort_if($workspace === null, 404);
        Gate::forUser($user)->authorize('access', $workspace);

        return $workspace;
    }

    private function helpText(): string
    {
        return implode("\n", [
            'دستورهای موجود:',
            '/start — شروع و فعال‌سازی حساب',
            '/brand — اصلاح برند تأییدشده',
            '/forget name|category|tone — حذف یک بخش حافظه برند',
            '/skip — گذشتن از پرسش اختیاری',
            '/new — ثبت پروژه جدید؛ سپس عکس محصولات را بفرست',
            '/product نام (و قیمت در خط دوم) — ثبت محصول با عکس‌های تازه همین پروژه',
            '/projects — آخرین پروژه‌ها',
            '/mybrand — مشاهده پروفایل برند',
            '/balance — مشاهده موجودی اعتبار',
            '/privacy — حریم خصوصی و حذف داده',
            '/terms — شرایط استفاده',
            '/retention — مدت نگهداری هر گروه داده',
            '/delete_data — حذف کامل داده‌ها و حساب (با تأیید)',
            '/support متن — ثبت درخواست پشتیبانی و دریافت رسید',
            '/ticket شناسه — پیگیری رسید',
            '/help — همین راهنما',
        ]);
    }
}
