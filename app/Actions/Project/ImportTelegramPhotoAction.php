<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Privacy\EnsureVoiceIntakeAllowedAction;
use App\Actions\Product\AttachTelegramPhotoToProductAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Data\Telegram\BotReply;
use App\Enums\ProjectState;
use App\Exceptions\TelegramFileTooLarge;
use App\Models\Asset;
use App\Models\ProductTable;
use App\Models\Project;
use App\Models\TelegramMediaIntake;
use App\Models\VoiceAsset;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramProductTablePreview;
use App\Services\Telegram\TelegramReplyService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Downloads one queued bot photo or product CSV on the server and stores it
 * through the same encrypted path as an API upload (ADR-022, ADR-024, ADR-040). Every ending deletes the
 * intake row; transport errors are thrown so the job retries.
 */
final readonly class ImportTelegramPhotoAction
{
    public function __construct(
        private TelegramMediaIntakeRepositoryInterface $intakes,
        private ProjectRepositoryInterface $projects,
        private AttachProjectAssetAction $attach,
        private AttachProjectVoiceAction $attachVoice,
        private EnsureVoiceIntakeAllowedAction $voiceAllowed,
        private AttachProductTableAction $attachTable,
        private TelegramProductTablePreview $tablePreview,
        private AttachTelegramPhotoToProductAction $attachToProduct,
        private ResolveTelegramInputProjectAction $inputProject,
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

        $table = $intake->kind === TelegramMediaIntake::KIND_TABLE;
        $voice = $intake->kind === TelegramMediaIntake::KIND_VOICE;
        $toCard = $intake->target_product_id !== null;
        if ($voice && $toCard) {
            $this->finish($intake, null);

            return;
        }
        // Switched off or consent withdrawn since the voice was queued: nothing is downloaded.
        if ($voice) {
            try {
                $this->voiceAllowed->execute($workspace);
            } catch (DomainException $e) {
                $this->finish($intake, $this->voiceRefusal($e, $project));

                return;
            }
        }
        // A batch takes files until approval (ADR-041); a photo for a card reopens an approved one.
        $notice = null;
        if (! $project->state->isOpenForInput() && ! ($toCard && $project->state === ProjectState::ProductApproved)) {
            if ($toCard) {
                $this->finish($intake, "پروژه «{$project->name}» دیگر عکس نمی‌پذیرد.");

                return;
            }
            // Approved after the file was queued: it goes where a new file would, never into the closed batch.
            [$project, $notice] = DB::transaction(fn (): array => $this->inputProject->execute($workspace));
        }

        $cap = (int) config($table ? 'karmandeman.tables.max_kilobytes' : ($voice ? 'karmandeman.voice.max_kilobytes' : 'karmandeman.assets.max_kilobytes')) * 1024;
        try {
            $file = $this->bot->getFile($this->cipher->decrypt($workspace, 'telegram_media_intakes.file_ref', $intake->file_ref));
            if ($file['size'] !== null && $file['size'] > $cap) {
                throw new TelegramFileTooLarge;
            }
            $bytes = $this->bot->downloadFile($file['path'], $cap);
        } catch (TelegramFileTooLarge) {
            $this->finish($intake, $table ? 'حجم فایل CSV بیش از '.intdiv($cap, 1024).' کیلوبایت است.'
                : ($voice ? 'حجم ویس بیش از دو مگابایت است.' : 'حجم این عکس بیش از ۱۰ مگابایت است.'));

            return;
        }
        if ($table) {
            $this->finish($intake, $this->attachTable($intake, $project, $bytes), $notice);

            return;
        }
        if ($voice) {
            $this->finish($intake, $this->attachVoice($intake, $project, $bytes), $notice);

            return;
        }
        if ($toCard) {
            $this->finish($intake, $this->attachToProduct->execute($intake, $project, $bytes));

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
                'ASSET_DAMAGED' => 'این عکس خراب است یا کامل نرسیده است؛ دوباره بفرست.',
                'PROJECT_NOT_ACCEPTING_INPUT' => "پروژه «{$project->name}» دیگر عکس نمی‌پذیرد.",
                'POLICY_CONSENT_REQUIRED' => 'پیش از ارسال عکس، شرایط و سیاست داده را با /start بپذیر.',
                // Erasure or suspension: the account gets no new message.
                default => null,
            });

            return;
        }

        $this->finish($intake, $replayed || $asset->wasRecentlyCreated
            ? "عکس به پروژه «{$project->name}» اضافه شد."
            : "این عکس قبلاً در پروژه «{$project->name}» ثبت شده بود.", $notice);
    }

    private function attachVoice(TelegramMediaIntake $intake, Project $project, #[\SensitiveParameter] string $bytes): ?string
    {
        try {
            [$voice, $replayed] = $this->attachVoice->execute(
                $intake->workspace, $intake->user, 'telegram-'.substr($intake->message_key, 0, 48),
                $project->public_id, $bytes, VoiceAsset::SOURCE_TELEGRAM, $intake->message_key,
            );
        } catch (AuthorizationException) {
            return null;
        } catch (DomainException $e) {
            return $this->voiceRefusal($e, $project);
        }

        return $replayed || $voice->wasRecentlyCreated
            ? "ویس در پروژه «{$project->name}» ذخیره شد؛ تبدیل به متن هنوز فعال نیست."
            : "این ویس قبلاً در پروژه «{$project->name}» ثبت شده بود.";
    }

    private function voiceRefusal(DomainException $e, Project $project): string
    {
        return match ($e->getMessage()) {
            'VOICE_UNSUPPORTED', 'VOICE_DAMAGED' => 'ویس سالم Ogg/Opus لازم است؛ این فایل پذیرفته نشد.',
            'VOICE_TOO_LARGE', 'VOICE_TOO_LONG' => 'ویس باید حداکثر دو دقیقه و دو مگابایت باشد.',
            'VOICE_INTAKE_DISABLED' => 'دریافت ویس هنوز فعال نیست؛ برای پیگیری /support را بفرست.',
            'PROJECT_NOT_ACCEPTING_INPUT' => "پروژه «{$project->name}» دیگر ویس نمی‌پذیرد.",
            'POLICY_CONSENT_REQUIRED' => 'پیش از ارسال ویس، شرایط و سیاست داده را با /start بپذیر.',
            default => 'این ویس ثبت نشد؛ دوباره بفرست.',
        };
    }

    /** The CSV through the API's path; the reply is its mapping for the seller to confirm (ADR-040). */
    private function attachTable(TelegramMediaIntake $intake, Project $project, #[\SensitiveParameter] string $bytes): BotReply|string|null
    {
        try {
            [$table, $parsed] = $this->attachTable->execute(
                $intake->workspace, $intake->user, 'telegram-'.substr($intake->message_key, 0, 48), $project->public_id, $bytes,
                ProductTable::SOURCE_TELEGRAM, $intake->message_key,
            );
        } catch (AuthorizationException) {
            return null;
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'TABLE_UNREADABLE' => 'این فایل خوانده نشد؛ آن را به‌صورت «CSV UTF-8» ذخیره کن و دوباره بفرست.',
                'TABLE_TOO_LARGE' => 'حجم فایل CSV بیش از حد مجاز است.',
                'TABLE_TOO_MANY_ROWS' => 'فایل بیش از '.config('karmandeman.tables.max_rows').' ردیف دارد؛ آن را چند فایل کن.',
                'TABLE_TOO_MANY_COLUMNS' => 'فایل بیش از '.config('karmandeman.tables.max_columns').' ستون دارد.',
                'TABLE_EMPTY' => 'فایل سطر عنوان و دست‌کم یک ردیف محصول ندارد.',
                'TABLE_NO_NAME_COLUMN' => 'ستون نام محصول پیدا نشد؛ عنوان آن ستون را «نام» بگذار و دوباره بفرست.',
                'TABLE_AMBIGUOUS_COLUMNS' => 'دو ستون برای یک مشخصه (مثلاً دو ستون قیمت) هست؛ یکی را حذف کن و دوباره بفرست.',
                'TABLE_MIXED_PRICE_UNITS' => 'قیمت‌های فایل هم تومان و هم ریال‌اند؛ همه را یک واحد کن و دوباره بفرست.',
                'TABLE_NOT_FOUND' => 'این فایل پیش‌تر کنار گذاشته شده بود؛ اگر لازم است دوباره بفرست.',
                'PROJECT_NOT_ACCEPTING_INPUT' => "پروژه «{$project->name}» دیگر فایل نمی‌پذیرد.",
                'POLICY_CONSENT_REQUIRED' => 'پیش از ارسال فایل، شرایط و سیاست داده را با /start بپذیر.',
                // Erasure or suspension: the account gets no new message.
                default => null,
            };
        }

        return $table->status === ProductTable::STATUS_PENDING
            ? $this->tablePreview->reply($table, $parsed, $project)
            : "این فایل قبلاً در پروژه «{$project->name}» ثبت شده بود.";
    }

    /** Called once the job has run out of retries. */
    public function fail(int $intakeId): void
    {
        $intake = $this->intakes->find($intakeId);
        if ($intake !== null) {
            $this->finish($intake, $intake->workspace->status === 'active'
                ? match ($intake->kind) {
                    TelegramMediaIntake::KIND_TABLE => 'دریافت فایل از تلگرام ناموفق بود؛ دوباره بفرست.',
                    TelegramMediaIntake::KIND_VOICE => 'دریافت ویس از تلگرام ناموفق بود؛ دوباره بفرست.',
                    default => 'دریافت عکس از تلگرام ناموفق بود؛ دوباره بفرست.',
                }
                : null);
        }
    }

    private function finish(TelegramMediaIntake $intake, BotReply|string|null $reply, ?string $notice = null): void
    {
        if ($reply !== null && $notice !== null) {
            $reply = is_string($reply) ? "{$notice}\n{$reply}" : new BotReply("{$notice}\n{$reply->text}", $reply->buttons);
        }
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
                $reply = is_string($reply) ? new BotReply($reply) : $reply;
                $this->replies->sendMessage($chatId, $reply->text, $reply->buttons);
            }
        });
    }
}
