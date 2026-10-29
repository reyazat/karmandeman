<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProjectState;
use App\Jobs\Project\ImportTelegramPhoto;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Telegram\TelegramSubjectKey;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A photo sent to the bot (ADR-024). Runs inside the webhook transaction: it
 * only records which file to fetch for which batch, and queues the download
 * after commit. Nothing is downloaded while the webhook waits.
 */
final readonly class QueueTelegramPhotoAction
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private TelegramMediaIntakeRepositoryInterface $intakes,
        private WorkspaceFieldCipher $cipher,
        private TelegramSubjectKey $subjects,
    ) {}

    /**
     * @param  array<string, mixed>  $message  a private-chat message with a photo or document
     * @return string|null the reply now, or null when the import job answers later
     */
    public function execute(Workspace $workspace, User $user, array $message): ?string
    {
        $file = $this->pick($message);
        if ($file === null) {
            return 'فقط عکس JPEG، PNG یا WebP پذیرفته می‌شود.';
        }
        $cap = (int) config('karmandeman.assets.max_kilobytes') * 1024;
        if ($file['size'] !== null && $file['size'] > $cap) {
            return 'حجم این عکس بیش از ۱۰ مگابایت است.';
        }
        try {
            $this->consent->execute($workspace);
        } catch (DomainException) {
            return 'پیش از ارسال عکس، شرایط و سیاست داده را با /start بپذیر.';
        }
        $project = $this->projects->latestInState($workspace, ProjectState::CollectingInput);
        if ($project === null) {
            return 'پروژه بازی برای دریافت عکس نداری؛ اول با /new یک پروژه بساز.';
        }
        Gate::forUser($user)->authorize('update', $project);
        // Same budget as the API upload route: each photo costs a download, encryption and storage.
        $limit = 'telegram-photo:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, (int) config('karmandeman.assets.uploads_per_minute'))) {
            return 'عکس‌ها خیلی سریع فرستاده شدند؛ یک دقیقه صبر کن و بقیه را بفرست.';
        }
        RateLimiter::hit($limit, 60);

        $intake = $this->intakes->createOnce(
            $workspace, $project, $user,
            $this->cipher->encrypt($workspace, 'telegram_media_intakes.file_ref', $file['id']),
            $file['size'],
            $this->subjects->message($message['chat']['id'], $message['message_id']),
        );
        if ($intake !== null) {
            ImportTelegramPhoto::dispatch($intake->id)->afterCommit();
        }

        return null;
    }

    /**
     * The largest photo size, or an image sent as a document (original quality).
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string, size: int|null}|null
     */
    private function pick(array $message): ?array
    {
        $document = $message['document'] ?? null;
        if (is_array($document)) {
            return in_array($document['mime_type'] ?? null, self::IMAGE_MIMES, true) && is_string($document['file_id'] ?? null)
                ? ['id' => $document['file_id'], 'size' => is_int($document['file_size'] ?? null) ? $document['file_size'] : null]
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

        return $best === null ? null : ['id' => $best['id'], 'size' => $best['size']];
    }
}
