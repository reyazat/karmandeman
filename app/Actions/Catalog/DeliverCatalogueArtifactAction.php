<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Exceptions\UndecryptableValue;
use App\Jobs\Export\GenerateCatalogueArtifact;
use App\Models\Artifact;
use App\Models\ProjectSnapshot;
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
 * Sends a ready catalogue pack, the PDF and one story image per product, to
 * the chat of the seller who pressed for it (ADR-052, ADR-053), from storage:
 * nothing is made or charged here. The seller must
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
        $snapshot = $artifact->snapshot;
        try {
            $documents = $this->documents($artifact, $snapshot);
        } catch (UndecryptableValue) {
            $this->remake($artifact, $userId, $chatId);

            return;
        }
        $caption = 'کاتالوگ PDF و '.(count($documents) - 1)." استوری نسخه نهایی شماره {$snapshot->number}. دریافت دوباره همین فایل‌ها رایگان است.";
        // An album holds at most ten files; the caption goes with the first.
        foreach (array_chunk($documents, 10) as $index => $group) {
            $text = $index === 0 ? $caption : '';
            if (count($group) === 1) {
                $this->bot->sendDocument($chatId, $group[0]['filename'], $group[0]['contents'], $text, $group[0]['mime']);
            } else {
                $this->bot->sendDocumentGroup($chatId, $group, $text);
            }
        }
    }

    /**
     * The PDF, then one story image per product of the snapshot (ADR-053).
     *
     * @return list<array{filename: string, contents: string, mime: string}>
     *
     * @throws UndecryptableValue when a stored file is lost, or the pack was made before it had story images
     */
    private function documents(Artifact $artifact, ProjectSnapshot $snapshot): array
    {
        $stories = $this->artifacts->stories($artifact);
        if (count($stories) !== count($snapshot->items)) {
            throw new UndecryptableValue('Pack story images are missing.');
        }
        $documents = [[
            'filename' => "catalogue-{$snapshot->public_id}.pdf",
            'contents' => $this->store->readObject($artifact->workspace, (string) $artifact->storage_key, $artifact->public_id, PrivateAssetStore::KIND_ARTIFACT),
            'mime' => 'application/pdf',
        ]];
        foreach ($stories as $story) {
            $documents[] = [
                'filename' => "story-{$story->position}-{$snapshot->public_id}.jpg",
                'contents' => $this->store->readObject($artifact->workspace, $story->storage_key, $story->public_id, PrivateAssetStore::KIND_ARTIFACT),
                'mime' => 'image/jpeg',
            ];
        }

        return $documents;
    }

    /** After the last try: the file is kept and paid for once, so the seller asks again for free. */
    public function notifyUndelivered(int $artifactId, int $userId): void
    {
        $artifact = $this->artifacts->find($artifactId);
        $chatId = $artifact === null ? null : $this->recipient($artifact, $userId);
        if ($chatId !== null) {
            $this->replies->sendMessage($chatId, 'کاتالوگ و استوری‌ها ساخته شدند ولی ارسال آن‌ها به تلگرام کامل نشد. دوباره دکمه کاتالوگ را بزن؛ همین فایل‌ها بدون هزینه فرستاده می‌شوند.');
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
            $this->replies->sendMessage($chatId, 'فایل‌های کاتالوگ در ذخیره کامل نبودند و دوباره ساخته می‌شوند؛ هزینه تازه‌ای ندارد.');
        });
    }
}
