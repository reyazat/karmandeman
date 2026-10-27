<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\Workspace;
use App\Repositories\Contracts\TelegramOnboardingRepositoryInterface;
use App\Services\Privacy\PilotPolicyService;
use DomainException;

final readonly class EnsurePolicyConsentAction
{
    public function __construct(
        private PilotPolicyService $policies,
        private TelegramOnboardingRepositoryInterface $consents,
    ) {}

    /**
     * Any channel mutating brand or project data needs the exact approved
     * policy accepted for this workspace, same as Telegram onboarding (FR-002).
     */
    public function execute(Workspace $workspace): void
    {
        $policy = $this->policies->current();
        if (! $policy->isApproved()
            || ! $this->consents->hasConsent($workspace, $policy->version, $policy->digest)) {
            throw new DomainException('POLICY_CONSENT_REQUIRED');
        }
    }
}
