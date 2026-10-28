<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Carbon\CarbonImmutable;

/** Time-based pruning of short-lived technical records (FR-084, ADR-015). Each method returns the affected row count. */
interface RetentionRepositoryInterface
{
    public function deleteConversationEventsBefore(CarbonImmutable $cutoff): int;

    /** Replay records whose token was issued before the cutoff or no longer exists. */
    public function deleteAuthReplaysBefore(CarbonImmutable $issuedBefore): int;

    /** Drop the response snapshot but keep key and fingerprint, so a replay cannot run the mutation again. */
    public function expireIdempotencySnapshotsBefore(CarbonImmutable $cutoff): int;

    /** Clear drafts untouched since the cutoff; a workspace with a named brand returns to ready. */
    public function resetOnboardingDraftsBefore(CarbonImmutable $cutoff): int;
}
