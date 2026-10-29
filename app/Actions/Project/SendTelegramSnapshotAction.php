<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Exceptions\SnapshotIntegrityFailed;
use App\Http\Resources\Project\ProjectSnapshotResource;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramReplyService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Worker side of the bot's JSON button (ADR-030): reads the batch through the
 * same verified path as the API, and sends it only while the snapshot the
 * button named is still the batch. A transport error is thrown to retry.
 */
final readonly class SendTelegramSnapshotAction
{
    public function __construct(
        private UserRepositoryInterface $users,
        private WorkspaceRepositoryInterface $workspaces,
        private ReadProjectSnapshotAction $read,
        private TelegramBotService $bot,
        private TelegramReplyService $replies,
    ) {}

    public function execute(int $userId, string $projectPublicId, int $snapshotNumber): void
    {
        $user = $this->users->find($userId);
        $workspace = $user === null ? null : $this->workspaces->currentFor($user);
        $chatId = $user === null ? null : $this->users->telegramChatId($user);
        // Erasure started after the press: send nothing (FR-086).
        if ($user === null || $workspace === null || $chatId === null || $workspace->status !== 'active') {
            return;
        }

        try {
            [$snapshot, $revisions] = $this->read->execute($workspace, $user, $projectPublicId);
        } catch (AuthorizationException) {
            return;
        } catch (SnapshotIntegrityFailed) {
            $this->replies->sendMessage($chatId, 'نسخه نهایی این پروژه قابل اعتماد نیست و فایل ارسال نشد.');

            return;
        } catch (DomainException) {
            $this->replies->sendMessage($chatId, 'این پروژه دیگر نسخه نهایی معتبر ندارد و فایل ارسال نشد.');

            return;
        }
        if ($snapshot->number !== $snapshotNumber) {
            $this->replies->sendMessage($chatId, 'پس از این دکمه نسخه نهایی تازه‌تری ثبت شده است؛ دکمه پیام تأیید تازه را بزن.');

            return;
        }

        $json = json_encode(new ProjectSnapshotResource($snapshot, $revisions), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->bot->sendDocument(
            $chatId, "karmandeman-{$snapshot->public_id}.json", $json,
            "نسخه نهایی شماره {$snapshot->number} پروژه «{$snapshot->project->name}»: ".count($revisions).' محصول.',
        );
    }
}
