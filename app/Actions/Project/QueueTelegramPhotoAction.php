<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProjectState;
use App\Jobs\Project\ImportTelegramPhoto;
use App\Models\TelegramMediaIntake;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Telegram\TelegramSubjectKey;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A photo or a product CSV sent to the bot (ADR-024, ADR-040). Runs inside the webhook transaction: it
 * only records which file to fetch for which batch, and queues the download
 * after commit. Nothing is downloaded while the webhook waits.
 */
final readonly class QueueTelegramPhotoAction
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Types Telegram clients report for a .csv file. */
    private const TABLE_MIMES = ['text/csv', 'text/comma-separated-values', 'application/csv', 'text/plain', 'application/vnd.ms-excel', 'application/octet-stream'];

    public function __construct(
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private ResolveTelegramInputProjectAction $inputProject,
        private TelegramMediaIntakeRepositoryInterface $intakes,
        private WorkspaceFieldCipher $cipher,
        private TelegramSubjectKey $subjects,
    ) {}

    /**
     * @param  array<string, mixed>  $message  a private-chat message with a photo or document
     * @param  array{0: string, 1: int}|null  $target  the product card the photo replies to, and the revision it shows
     * @return string|null the reply now (a refusal, or where a new batch was opened), or null when only the import job answers
     */
    public function execute(Workspace $workspace, User $user, array $message, ?array $target = null): ?string
    {
        $file = $this->pick($message);
        if ($file === null) {
            return 'فقط عکس JPEG، PNG یا WebP یا فایل CSV محصولات پذیرفته می‌شود.';
        }
        $table = $file['kind'] === TelegramMediaIntake::KIND_TABLE;
        $cap = (int) config($table ? 'karmandeman.tables.max_kilobytes' : 'karmandeman.assets.max_kilobytes') * 1024;
        if ($file['size'] !== null && $file['size'] > $cap) {
            return $table ? 'حجم فایل CSV بیش از '.intdiv($cap, 1024).' کیلوبایت است.' : 'حجم این عکس بیش از ۱۰ مگابایت است.';
        }
        try {
            $this->consent->execute($workspace);
        } catch (DomainException) {
            return 'پیش از ارسال عکس، شرایط و سیاست داده را با /start بپذیر.';
        }
        // Same budget as the API upload route: each photo costs a download, encryption and storage.
        $limit = 'telegram-photo:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, (int) config('karmandeman.assets.uploads_per_minute'))) {
            return 'عکس‌ها خیلی سریع فرستاده شدند؛ یک دقیقه صبر کن و بقیه را بفرست.';
        }
        RateLimiter::hit($limit, 60);

        $notice = null;
        $cardTarget = null;
        if ($target !== null) {
            // A photo in reply to a product card goes to that product's batch (ADR-041).
            $product = $this->products->findPublicForWorkspace($workspace, $target[0]);
            $project = $product === null || $file['kind'] !== TelegramMediaIntake::KIND_PHOTO ? null : $this->projects->findForWorkspace($workspace, (int) $product->project_id);
            if ($product === null || $project === null || Gate::forUser($user)->denies('update', $product)) {
                return $file['kind'] === TelegramMediaIntake::KIND_PHOTO ? 'محصول این کارت یافت نشد.' : 'فایل CSV را بدون پاسخ به کارت بفرست.';
            }
            if ($project->state === ProjectState::Cancelled) {
                return "پروژه «{$project->name}» لغو شده است و عکس تازه نمی‌گیرد.";
            }
            $cardTarget = [$product->id, $target[1]];
        } else {
            [$project, $notice] = $this->inputProject->execute($workspace);
        }
        Gate::forUser($user)->authorize('update', $project);

        $intake = $this->intakes->createOnce(
            $workspace, $project, $user,
            $this->cipher->encrypt($workspace, 'telegram_media_intakes.file_ref', $file['id']),
            $file['size'],
            $this->subjects->message($message['chat']['id'], $message['message_id']),
            $file['kind'],
            $cardTarget,
        );
        if ($intake !== null) {
            ImportTelegramPhoto::dispatch($intake->id)->afterCommit();
        }

        return $notice;
    }

    /**
     * The largest photo size, or an image sent as a document (original quality).
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string, size: int|null, kind: string}|null
     */
    private function pick(array $message): ?array
    {
        $document = $message['document'] ?? null;
        if (is_array($document)) {
            if (! is_string($document['file_id'] ?? null)) {
                return null;
            }
            $size = is_int($document['file_size'] ?? null) ? $document['file_size'] : null;
            if (in_array($document['mime_type'] ?? null, self::IMAGE_MIMES, true)) {
                return ['id' => $document['file_id'], 'size' => $size, 'kind' => TelegramMediaIntake::KIND_PHOTO];
            }
            // A product table is named .csv; its bytes are checked again after download (ADR-040).
            $name = is_string($document['file_name'] ?? null) ? $document['file_name'] : '';

            return str_ends_with(strtolower($name), '.csv') && in_array($document['mime_type'] ?? 'text/csv', self::TABLE_MIMES, true)
                ? ['id' => $document['file_id'], 'size' => $size, 'kind' => TelegramMediaIntake::KIND_TABLE]
                : null;
        }
        $best = null;
        foreach ((array) ($message['photo'] ?? []) as $size) {
            if (! is_array($size) || ! is_string($size['file_id'] ?? null)) {
                continue;
            }
            $area = (int) ($size['width'] ?? 0) * (int) ($size['height'] ?? 0);
            if ($best === null || $area > $best['area']) {
                $best = ['id' => $size['file_id'], 'size' => is_int($size['file_size'] ?? null) ? $size['file_size'] : null, 'area' => $area];
            }
        }

        return $best === null ? null : ['id' => $best['id'], 'size' => $best['size'], 'kind' => TelegramMediaIntake::KIND_PHOTO];
    }
}
