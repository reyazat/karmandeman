<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\ProjectState;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use DomainException;

/**
 * A correction from the bot reopens an approved batch by itself (ADR-041,
 * amending ADR-029): the reopen is the same ReopenProjectAction as the
 * button, so the earlier snapshot stays as history and no output is made
 * until the batch is approved again (PRD §6). Only a card of the current
 * revision gets here, so an outdated card never reopens anything. A batch
 * past approval (quote or credit) is not reopened. A reopened batch becomes
 * the bot's current batch like the reopen button, so the next /done finishes
 * it and not the batch input went to meanwhile (ADR-028).
 */
final readonly class ReopenForCorrectionAction
{
    public function __construct(
        private ReopenProjectAction $reopen,
        private ProjectRepositoryInterface $projects,
    ) {}

    /**
     * @return array{Project, string|null} the batch, and the notice when it was reopened
     *
     * @throws DomainException PROJECT_PAST_APPROVAL
     */
    public function execute(Workspace $workspace, User $user, string $key, Project $project): array
    {
        if ($project->state === ProjectState::ProductApproved) {
            [$project] = $this->reopen->execute($workspace, $user, $key, $project->public_id, $project->version);
            // Under the workspace lock the reopen took, in the caller's transaction (ADR-031).
            $this->projects->selectForBot($project);

            return [$project, "پروژه «{$project->name}» برای اصلاح دوباره باز شد و پروژه جاری ربات است؛ نسخه نهایی قبلی در سابقه محفوظ است و تا تأیید دوباره خروجی تازه ساخته نمی‌شود. وقتی تمام شد /done بفرست.\n"];
        }
        if ($project->state->hasApprovedSnapshot()) {
            throw new DomainException('PROJECT_PAST_APPROVAL');
        }

        return [$project, null];
    }
}
