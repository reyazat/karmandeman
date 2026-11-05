<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Wallet\ReleaseCreditsAction;
use App\Models\Artifact;
use App\Repositories\Contracts\ArtifactRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Telegram\TelegramReplyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A catalogue file that could not be made (ADR-052): the hold is released, so
 * a Karmandeman error costs nothing, and the seller is told to ask again.
 * Settles once: a file already ready or failed is left as it is.
 */
final readonly class FailCatalogueArtifactAction
{
    public function __construct(
        private ArtifactRepositoryInterface $artifacts,
        private WalletRepositoryInterface $wallets,
        private ReleaseCreditsAction $release,
        private UserRepositoryInterface $users,
        private TelegramReplyService $replies,
        private WorkspaceRepositoryInterface $workspaces,
    ) {}

    public function execute(Artifact $artifact, string $errorCode): void
    {
        DB::transaction(function () use ($artifact, $errorCode): void {
            // Same lock order as the request and the capture: workspace, file, wallet.
            $this->workspaces->lock($artifact->workspace);
            $locked = $this->artifacts->lock($artifact);
            if ($locked->status !== Artifact::STATUS_QUEUED) {
                return;
            }
            $this->artifacts->markFailed($locked, $errorCode);
            $hold = $locked->hold_entry_id === null ? null : $this->wallets->findEntryById($locked->hold_entry_id);
            // A file made and paid for once keeps that payment; only a first-time hold is released.
            if ($hold !== null && $locked->generated_at === null) {
                $this->release->execute($hold, Artifact::KIND_CATALOGUE_PDF);
            }
            $user = $locked->requested_by === null ? null : $this->users->find($locked->requested_by);
            $chatId = $user === null ? null : $this->users->telegramChatId($user);
            if ($chatId !== null && $locked->workspace->status === 'active') {
                $this->replies->sendMessage($chatId, $errorCode === 'SNAPSHOT_NOT_CURRENT'
                    ? 'کاتالوگ و استوری‌ها ساخته نشدند، چون نسخه نهایی پروژه پس از درخواست تغییر کرد. اعتباری کسر نشد؛ از پیام تأیید تازه دوباره درخواست بده.'
                    : 'ساخت کاتالوگ و استوری‌ها انجام نشد و اعتباری کسر نشد. دوباره دکمه کاتالوگ را بزن؛ اگر باز تکرار شد /support را بفرست.');
            }
        });
        Log::warning('catalogue.generation_failed', ['artifact' => $artifact->public_id, 'error_code' => $errorCode]);
    }
}
