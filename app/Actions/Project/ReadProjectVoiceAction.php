<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Exceptions\UndecryptableValue;
use App\Models\User;
use App\Models\VoiceAsset;
use App\Models\Workspace;
use App\Repositories\Contracts\VoiceAssetRepositoryInterface;
use App\Services\Storage\PrivateAssetStore;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

final readonly class ReadProjectVoiceAction
{
    public function __construct(
        private VoiceAssetRepositoryInterface $voices,
        private PrivateAssetStore $store,
    ) {}

    /** @return array{VoiceAsset, string} */
    public function execute(Workspace $workspace, User $user, string $publicId): array
    {
        $voice = $this->voices->findPublicForWorkspace($workspace, $publicId)
            ?? throw new DomainException('VOICE_NOT_FOUND');
        Gate::forUser($user)->authorize('view', $voice);
        try {
            return [$voice, $this->store->readObject($workspace, $voice->storage_key, $voice->public_id, PrivateAssetStore::KIND_VOICE)];
        } catch (UndecryptableValue) {
            Log::critical('voice.integrity_failed', ['voice' => $voice->public_id]);

            throw new DomainException('VOICE_INTEGRITY_FAILED');
        }
    }
}
