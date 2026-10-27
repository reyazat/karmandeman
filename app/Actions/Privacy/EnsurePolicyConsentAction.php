<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceConsentRepositoryInterface;
use App\Services\Privacy\PilotPolicyService;
use DomainException;

final readonly class EnsurePolicyConsentAction
{
    public function __construct(
        private PilotPolicyService $policies,
        private WorkspaceConsentRepositoryInterface $consents,
    ) {}

    /**
     * Any channel mutating brand or project data needs the exact approved
     * policy accepted for this workspace, same as Telegram onboarding (FR-002).
     */
    public function execute(Workspace $workspace): void
    {
        $policy = $this->policies->current();
        if (! $policy->isApproved()
            || $this->consents->find($workspace, $policy->version)?->policy_document_hash !== $policy->digest) {
            throw new DomainException('POLICY_CONSENT_REQUIRED');
        }
    }
}
