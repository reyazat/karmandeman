<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Exceptions\UndecryptableValue;
use App\Jobs\Export\GenerateCatalogueArtifact;
use App\Models\Artifact;
use App\Repositories\Contracts\ArtifactRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Storage\PrivateAssetStore;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramReplyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sends a ready catalogue file to the chat of the seller who pressed for it
 * (ADR-052), from storage: nothing is made or charged here. The seller must
 * still see the batch. A transport error is thrown so the job retries; the
 * file stays, so asking again resends it. A stored file that is lost is made
 * again without a new charge: that loss is Karmandeman's error.
 */
final readonly class DeliverCatalogueArtifactAction
{
    public function __construct(
        private ArtifactRepositoryInterface $artifacts,
        private UserRepositoryInterface $users,
        private WorkspaceRepositoryInterface $workspaces,
        private ProjectRepositoryInterface $projects,
        private PrivateAssetStore $store,
        private TelegramBotService $bot,
        private TelegramReplyService $replies,
    ) {}

    public function execute(int $artifactId, int $userId): void
    {
        $artifact = $this->artifacts->find($artifactId);
        $chatId = $artifact === null ? null : $this->recipient($artifact, $userId);
        if ($artifact === null || $chatId === null || $artifact->status !== Artifact::STATUS_READY || $artifact->storage_key === null) {
            return;
        }
        try {
            $bytes = $this->store->readObject($artifact->workspace, $artifact->storage_key, $artifact->public_id, PrivateAssetStore::KIND_ARTIFACT);
        } catch (UndecryptableValue) {
            $this->remake($artifact, $userId, $chatId);

            return;
        }
        $snapshot = $artifact->snapshot;
        $this->bot->sendDocument(
            $chatId, "catalogue-{$snapshot->public_id}.pdf", $bytes,
            "کاتالوگ PDF نسخه نهایی شماره {$snapshot->number}. دریافت دوباره همین فایل رایگان است.",
            'application/pdf',
        );
    }

    /** After the last try: the file is kept and paid for once, so the seller asks again for free. */
    public function notifyUndelivered(int $artifactId, int $userId): void
    {
        $artifact = $this->artifacts->find($artifactId);
        $chatId = $artifact === null ? null : $this->recipient($artifact, $userId);
        if ($chatId !== null) {
            $this->replies->sendMessage($chatId, 'کاتالوگ PDF ساخته شد ولی ارسال آن به تلگرام انجام نشد. دوباره دکمه کاتالوگ را بزن؛ همین فایل بدون هزینه فرستاده می‌شود.');
        }
    }

    /** The chat of a seller of this active workspace who may still see the batch, or null (FR-086). */
    private function recipient(Artifact $artifact, int $userId): ?string
    {
        $user = $this->users->find($userId);
        $workspace = $user === null ? null : $this->workspaces->currentFor($user);
        if ($workspace === null || $workspace->id !== $artifact->workspace_id || $workspace->status !== 'active') {
            return null;
        }
        $project = $this->projects->findForWorkspace($workspace, $artifact->project_id);
        if ($project === null || Gate::forUser($user)->denies('view', $project)) {
            return null;
        }

        return $this->users->telegramChatId($user);
    }

    private function remake(Artifact $artifact, int $userId, string $chatId): void
    {
        DB::transaction(function () use ($artifact, $userId, $chatId): void {
            $this->workspaces->lock($artifact->workspace);
            $locked = $this->artifacts->lock($artifact);
            if ($locked->status !== Artifact::STATUS_READY) {
                return;
            }
            $this->artifacts->requeuePaid($locked, $userId);
            GenerateCatalogueArtifact::dispatch($locked->id)->onConnection('export')->onQueue('export')->beforeCommit();
            $this->replies->sendMessage($chatId, 'فایل کاتالوگ در ذخیره پیدا نشد و دوباره ساخته می‌شود؛ هزینه تازه‌ای ندارد.');
        });
    }
}
