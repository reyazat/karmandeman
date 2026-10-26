<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Actions\Brand\EnsureBrandAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Project\CreateProjectAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\Gate;

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
        private CreateProjectAction $createProject,
        private TelegramReplyService $bot,
    ) {}

    public function handleUpdate(array $update): void
    {
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

        if ($chatId === null || $text === '') {
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
            '/privacy' => $this->bot->sendMessage(
                $chatId,
                'این نسخه آزمایشی هنوز امکان ثبت درخواست حذف داده در ربات ندارد. فعلاً فایل یا اطلاعات تجاری ارسال نکن.'
            ),
            '/support' => $this->bot->sendMessage(
                $chatId,
                'ثبت و پیگیری درخواست پشتیبانی در این نسخه هنوز فعال نشده است.'
            ),
            default => $this->bot->sendMessage(
                $chatId,
                'پردازش فایل و متن محصول در این نسخه هنوز فعال نیست. برای راهنما /help را بفرست.'
            ),
        };
    }

    private function handleStart(array $message, int|string $chatId): void
    {
        $from = $message['from'] ?? [];

        $this->register->execute(TelegramUserData::fromArray($from));

        $this->bot->sendMessage(
            $chatId,
            "سلام! به «کارمند من» خوش آمدی.\n".
            'کارمند آماده‌سازی محصول برای تو فعال شد. با /help شروع کن.'
        );
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
        $brand = $this->ensureBrand->execute($this->workspaceFor($user));

        $name = $brand->name ?? '—';
        $unit = $brand->currency_display_unit === 'irr' ? 'ریال' : 'تومان';

        $this->bot->sendMessage(
            $chatId,
            "برند: {$name}\nواحد قیمت: {$unit}\nلحن: ".($brand->tone ?? '—')
        );
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
            '/new — ثبت پروژه جدید (دریافت فایل هنوز فعال نیست)',
            '/projects — آخرین پروژه‌ها',
            '/mybrand — مشاهده پروفایل برند',
            '/balance — مشاهده موجودی اعتبار',
            '/privacy — حریم خصوصی و حذف داده',
            '/support — پشتیبانی',
            '/help — همین راهنما',
        ]);
    }
}
