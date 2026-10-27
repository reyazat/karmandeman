<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Brand\EnsureBrandAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Project\CreateProjectAction;
use App\Actions\Workspace\RecordServiceRequestAction;
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
    public function __construct(
        private RegisterTelegramUserAction $register,
        private WorkspaceRepositoryInterface $workspaces,
        private WalletRepositoryInterface $wallets,
        private ProjectRepositoryInterface $projects,
        private EnsureBrandAction $ensureBrand,
        private AdvanceTelegramOnboardingAction $onboarding,
        private CreateProjectAction $createProject,
        private RecordServiceRequestAction $serviceRequests,
        private TelegramReplyService $bot,
        private PilotPolicyService $policies,
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

        if ($text === '') {
            $this->replyOnboarding($message, $chatId, 'prompt');

            return;
        }
        $command = strtok($text, " \n");

        match ($command) {
            '/start' => $this->handleStart($message, $chatId),
            '/help' => $this->bot->sendMessage($chatId, $this->helpText()),
            '/balance' => $this->handleBalance($message, $chatId),
            '/new' => $this->handleNew($message, $chatId, $text),
            '/projects' => $this->handleProjects($message, $chatId),
            '/mybrand' => $this->handleMyBrand($message, $chatId),
            '/brand' => $this->replyOnboarding($message, $chatId, 'edit'),
            '/skip' => $this->replyOnboarding($message, $chatId, 'skip'),
            '/confirm' => $this->replyOnboarding($message, $chatId, 'confirm'),
            '/forget' => $this->replyOnboarding($message, $chatId, 'forget', trim(mb_substr($text, mb_strlen('/forget')))),
            '/terms' => $this->handlePolicyDocument($chatId, 'terms'),
            '/privacy' => $this->handlePolicyDocument($chatId, 'privacy'),
            '/retention' => $this->handlePolicyDocument($chatId, 'retention'),
            '/support' => $this->handleServiceRequest($message, $chatId, $update['update_id'], 'support', trim(mb_substr($text, mb_strlen('/support')))),
            '/delete_data' => $this->handleServiceRequest($message, $chatId, $update['update_id'], 'data_deletion'),
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
            'پروژه ثبت شد؛ دریافت و پردازش فایل هنوز فعال نیست. فعلاً فایل تجاری ارسال نکن.'
        );
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
        $from = $callback['from'] ?? [];
        $chat = $callback['message']['chat'] ?? [];
        if (($chat['type'] ?? null) !== 'private' || ($chat['id'] ?? null) !== ($from['id'] ?? null)) {
            return;
        }
        $data = explode(':', $callback['data'] ?? '');
        $event = $data[1] ?? '';
        $value = match ($event) {
            'accept', 'unit', 'skip' => $data[2] ?? null,
            'confirm' => isset($data[2], $data[3]) ? "{$data[2]}:{$data[3]}" : null,
            default => null,
        };
        $this->replyOnboarding(['from' => $from, 'chat' => $chat], $chat['id'], $event, $value);
        if (isset($callback['id'])) {
            $this->bot->acknowledge($callback['id']);
        }
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

    private function handleServiceRequest(array $message, int|string $chatId, int $updateId, string $kind, ?string $details = null): void
    {
        if ($kind === 'support' && ($details === null || $details === '')) {
            $this->bot->sendMessage($chatId, 'برای پشتیبانی، /support و سپس شرح کوتاه مشکل را بفرستید.');

            return;
        }
        if ($details !== null && mb_strlen($details) > 500) {
            $this->bot->sendMessage($chatId, 'شرح درخواست باید حداکثر ۵۰۰ نویسه باشد.');

            return;
        }
        $user = $this->resolveUser($message);
        $ticket = $this->serviceRequests->execute(
            $this->workspaceFor($user), $user, $kind, 'telegram:'.$updateId, $details
        );
        $label = $kind === 'data_deletion' ? 'درخواست حذف داده' : 'درخواست پشتیبانی';
        $this->bot->sendMessage($chatId, "{$label} ثبت شد؛ رسید: {$ticket->public_id}\nبرای وضعیت /ticket {$ticket->public_id} را بفرستید. ثبت درخواست حذف به معنی اجرای فوری آن نیست.");
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
            '/new — ثبت پروژه جدید (دریافت فایل هنوز فعال نیست)',
            '/projects — آخرین پروژه‌ها',
            '/mybrand — مشاهده پروفایل برند',
            '/balance — مشاهده موجودی اعتبار',
            '/privacy — حریم خصوصی و حذف داده',
            '/terms — شرایط استفاده',
            '/retention — مدت نگهداری هر گروه داده',
            '/delete_data — ثبت درخواست حذف داده و دریافت رسید',
            '/support متن — ثبت درخواست پشتیبانی و دریافت رسید',
            '/ticket شناسه — پیگیری رسید',
            '/help — همین راهنما',
        ]);
    }
}
