<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\User;
use App\Models\Workspace;

/**
 * Short-lived signed callback data for the Telegram deletion button. Binds the
 * click to the account, workspace and issue time; 64-bit tag fits callback limits.
 */
final readonly class TelegramDeletionConfirmation
{
    public const TTL_SECONDS = 600;

    private const CLOCK_SKEW_SECONDS = 60;

    public function callbackData(User $user, Workspace $workspace, int $issuedAt): string
    {
        return 'delete:confirm:'.$issuedAt.':'.$this->signature($user, $workspace, $issuedAt);
    }

    public function isValid(User $user, Workspace $workspace, string $callbackData, int $now): bool
    {
        if (preg_match('/^delete:confirm:([0-9]{1,12}):([a-f0-9]{16})$/D', $callbackData, $parts) !== 1) {
            return false;
        }
        $issuedAt = (int) $parts[1];
        $age = $now - $issuedAt;

        return $age >= -self::CLOCK_SKEW_SECONDS && $age <= self::TTL_SECONDS
            && hash_equals($this->signature($user, $workspace, $issuedAt), $parts[2]);
    }

    private function signature(User $user, Workspace $workspace, int $issuedAt): string
    {
        return substr(hash_hmac('sha256', "account-deletion:{$user->id}:{$workspace->public_id}:{$issuedAt}", (string) config('app.key')), 0, 16);
    }
}
