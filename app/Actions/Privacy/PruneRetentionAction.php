<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Repositories\Contracts\RetentionRepositoryInterface;
use App\Repositories\Contracts\TelegramOutboxRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * One hourly pass of the retention limits proposed in the current policy
 * bundle (FR-084, ADR-015). Limits are hours from creation; retries and
 * internal processing never extend them.
 */
final readonly class PruneRetentionAction
{
    public function __construct(
        private RetentionRepositoryInterface $retention,
        private TelegramOutboxRepositoryInterface $outbox,
    ) {}

    /** @return array<string, int> affected rows per category */
    public function execute(CarbonImmutable $now): array
    {
        $hours = fn (string $key): CarbonImmutable => $now->subHours((int) config("karmandeman.retention.{$key}"));

        return [
            'conversation_events' => $this->retention->deleteConversationEventsBefore($hours('conversation_event_hours')),
            'telegram_outbox' => $this->outbox->forgetCreatedBefore($hours('queued_message_hours'), $hours('failed_message_hours')),
            'auth_replays' => $this->retention->deleteAuthReplaysBefore($hours('auth_replay_hours')),
            'api_snapshots' => $this->retention->expireIdempotencySnapshotsBefore($hours('api_snapshot_hours')),
            'onboarding_drafts' => $this->retention->resetOnboardingDraftsBefore($hours('onboarding_draft_hours')),
        ];
    }
}
