<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\ServiceRequest;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ServiceRequestRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use DomainException;

final readonly class EloquentServiceRequestRepository implements ServiceRequestRepositoryInterface
{
    public function __construct(private WorkspaceFieldCipher $cipher) {}

    public function record(Workspace $workspace, User $user, string $kind, string $key, ?string $details = null): ServiceRequest
    {
        $existing = ServiceRequest::query()->where('external_key', $key)->first();
        if ($existing !== null) {
            if ($existing->workspace_id !== $workspace->id || $existing->user_id !== $user->id
                || $existing->kind !== $kind || $existing->details !== $details) {
                throw new DomainException('SERVICE_REQUEST_KEY_CONFLICT');
            }

            return $existing;
        }

        return ServiceRequest::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'kind' => $kind,
            'external_key' => $key,
            'details' => $details,
            'details_hash' => $details === null ? null : $this->detailsHash($workspace, $details),
            'status' => 'received',
        ]);
    }

    public function findFor(Workspace $workspace, string $publicId): ?ServiceRequest
    {
        return ServiceRequest::query()->where('workspace_id', $workspace->id)
            ->where('public_id', $publicId)->first();
    }

    public function pendingSupportFor(Workspace $workspace, string $details): ?ServiceRequest
    {
        return ServiceRequest::query()->where('workspace_id', $workspace->id)
            ->where('kind', 'support')->where('status', 'received')
            ->where('details_hash', $this->detailsHash($workspace, $details))->first();
    }

    /** Keyed by the workspace's own data key, so the digest is erased with it (ADR-013). */
    private function detailsHash(Workspace $workspace, string $details): string
    {
        return $this->cipher->mac($workspace, 'service_requests.details_hash', $details);
    }
}
