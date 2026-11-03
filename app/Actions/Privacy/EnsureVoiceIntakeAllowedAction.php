<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\Workspace;
use App\Services\Privacy\PilotPolicyService;
use DomainException;

/** Voice is a new category of private data, not covered by the old consent. */
final readonly class EnsureVoiceIntakeAllowedAction
{
    public function __construct(
        private EnsurePolicyConsentAction $consent,
        private PilotPolicyService $policies,
    ) {}

    public function execute(Workspace $workspace): void
    {
        if (! config('karmandeman.voice.intake_enabled') || app()->isProduction()
            || ! $this->policies->current()->allowsVoiceIntake()) {
            throw new DomainException('VOICE_INTAKE_DISABLED');
        }

        $this->consent->execute($workspace);
    }
}
