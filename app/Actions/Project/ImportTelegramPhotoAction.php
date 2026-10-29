<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Enums\ProjectState;
use App\Exceptions\TelegramFileTooLarge;
use App\Models\Asset;
use App\Models\TelegramMediaIntake;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramReplyService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Downloads one queued bot photo on the server and stores it through the same
 * encrypted path as an API upload (ADR-022, ADR-024). Every ending deletes the
 * intake row; transport errors are thrown so the job retries.
 */
final readonly class ImportTelegramPhotoAction
{
    public function __construct(
        private TelegramMediaIntakeRepositoryInterface $intakes,
        private ProjectRepositoryInterface $projects,
        private AttachProjectAssetAction $attach,
        private WorkspaceFieldCipher $cipher,
        private TelegramBotService $bot,
        private TelegramReplyService $replies,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    public function execute(int $intakeId): void
    {
        $intake = $this->intakes->find($intakeId);
        if ($intake === null) {
            return;
        }
        $workspace = $intake->workspace;
        // Erasure started after the photo was queued: fetch nothing (FR-086).
        if ($workspace->status !== 'active') {
            $this->finish($intake, null);

            return;
        }
        $project = $this->projects->findForWorkspace($workspace, $intake->project_id);
        if ($project === null) {
            $this->finish($intake, null);

            return;
        }

        // Only the batch still collecting input takes photos, as when the photo was queued.
        if ($project->state !== ProjectState::CollectingInput) {
            $this->finish($intake, "پروژه «{$project->name}» دیگر عکس نمی‌پذیرد.");

            return;
        }

        $cap = (int) config('karmandeman.assets.max_kilobytes') * 1024;
        try {
            $file = $this->bot->getFile($this->cipher->decrypt($workspace, 'telegram_media_intakes.file_ref', $intake->file_ref));
            if ($file['size'] !== null && $file['size'] > $cap) {
                throw new TelegramFileTooLarge;
            }
            $bytes = $this->bot->downloadFile($file['path'], $cap);
        } catch (TelegramFileTooLarge) {
            $this->finish($intake, 'حجم این عکس بیش از ۱۰ مگابایت است.');

            return;
        }

        try {
            [$asset, $replayed] = $this->attach->execute(
                $workspace, $intake->user, 'telegram-'.substr($intake->message_key, 0, 48), $project->public_id, $bytes,
                Asset::SOURCE_TELEGRAM, $intake->message_key,
            );
        } catch (AuthorizationException) {
            $this->finish($intake, null);

            return;
        } catch (DomainException $e) {
            $this->finish($intake, match ($e->getMessage()) {
                'ASSET_UNSUPPORTED' => 'فقط عکس JPEG، PNG یا WebP پذیرفته می‌شود.',
                'ASSET_TOO_LARGE' => 'ابعاد این عکس بیش از حد مجاز است.',
                'PROJECT_NOT_ACCEPTING_INPUT' => "پروژه «{$project->name}» دیگر عکس نمی‌پذیرد.",
                'POLICY_CONSENT_REQUIRED' => 'پیش از ارسال عکس، شرایط و سیاست داده را با /start بپذیر.',
                // Erasure or suspension: the account gets no new message.
                default => null,
            });

            return;
        }

        $this->finish($intake, $replayed || $asset->wasRecentlyCreated
            ? "عکس به پروژه «{$project->name}» اضافه شد."
            : "این عکس قبلاً در پروژه «{$project->name}» ثبت شده بود.");
    }

    /** Called once the job has run out of retries. */
    public function fail(int $intakeId): void
    {
        $intake = $this->intakes->find($intakeId);
        if ($intake !== null) {
            $this->finish($intake, $intake->workspace->status === 'active' ? 'دریافت عکس از تلگرام ناموفق بود؛ دوباره بفرست.' : null);
        }
    }

    private function finish(TelegramMediaIntake $intake, ?string $reply): void
    {
        DB::transaction(function () use ($intake, $reply): void {
            // Workspace lock first, in the purge's order. Erasure that started meanwhile
            // gets no new message, and a reply queued first is seen by the purge (FR-086).
            try {
                $this->lockActive->execute($intake->workspace);
            } catch (DomainException) {
                $reply = null;
            }
            $this->intakes->delete($intake);
            $chatId = $reply === null ? null : $intake->user->identities()->where('provider', 'telegram')->value('external_id');
            if ($chatId !== null) {
                $this->replies->sendMessage($chatId, (string) $reply);
            }
        });
    }
}
