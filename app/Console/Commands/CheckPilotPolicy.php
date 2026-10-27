<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Privacy\PilotPolicyService;
use Illuminate\Console\Command;

final class CheckPilotPolicy extends Command
{
    protected $signature = 'policy:check';

    protected $description = 'Read-only check of policy approval, content binding and recorded release evidence';

    public function handle(PilotPolicyService $policies): int
    {
        $policy = $policies->current();
        $this->line(json_encode([
            'version' => $policy->version,
            'bundle_sha256' => $policy->digest,
            'ready' => $policy->isApproved(),
            'blockers' => $policy->blockers,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $policy->isApproved() ? self::SUCCESS : self::FAILURE;
    }
}
