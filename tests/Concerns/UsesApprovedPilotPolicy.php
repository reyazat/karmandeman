<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use App\Repositories\Contracts\WorkspaceConsentRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Privacy\PilotPolicyService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

trait UsesApprovedPilotPolicy
{
    private function useApprovedPilotPolicy(string $version = 'test-policy-v1'): void
    {
        $path = storage_path('framework/testing/policy-'.Str::uuid());
        $files = new Filesystem;
        $files->copyDirectory(resource_path('policies/pilot-2026-10-27-v3'), $path);
        config(['pilot_policy.directory' => $path]);
        $retention = json_decode($files->get($path.'/retention.json'), true, flags: JSON_THROW_ON_ERROR);
        $retention['status'] = 'approved';
        $files->put($path.'/retention.json', json_encode($retention, JSON_THROW_ON_ERROR));
        $manifest = json_decode($files->get($path.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['version'] = $version;
        $manifest['status'] = 'approved';
        $manifest['approval'] = [
            'product_owner' => 'Synthetic test owner',
            'privacy_reviewer' => 'Synthetic test reviewer',
            'approved_at' => '2026-10-01T00:00:00Z',
            'bundle_sha256' => null,
        ];
        foreach ($manifest['release_evidence'] as $key => $_) {
            $manifest['release_evidence'][$key] = 'Synthetic fixture; no production approval';
        }
        $files->put($path.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $manifest['approval']['bundle_sha256'] = app(PilotPolicyService::class)->current()->digest;
        $files->put($path.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(fn () => $files->deleteDirectory($path));
    }

    private function grantPilotConsent(User $user): void
    {
        $policy = app(PilotPolicyService::class)->current();
        app(WorkspaceConsentRepositoryInterface::class)->record(
            app(WorkspaceRepositoryInterface::class)->currentFor($user), $user, $policy->version, $policy->digest,
        );
    }

    private function policyConsentToken(): string
    {
        return app(PilotPolicyService::class)->current()->consentToken();
    }
}
