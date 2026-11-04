<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsureVoiceIntakeAllowedAction;
use App\Actions\Wallet\EnsureInputCreditAction;
use App\Models\User;
use App\Models\VoiceAsset;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\VoiceAssetRepositoryInterface;
use App\Services\Storage\OggOpusInspector;
use App\Services\Storage\PrivateAssetStore;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Store a checked, untranscribed voice in the seller's open batch. */
final readonly class AttachProjectVoiceAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsureVoiceIntakeAllowedAction $consent,
        private EnsureInputCreditAction $credit,
        private ProjectRepositoryInterface $projects,
        private VoiceAssetRepositoryInterface $voices,
        private PrivateAssetStore $store,
        private OggOpusInspector $inspector,
    ) {}

    /** @return array{VoiceAsset, bool} */
    public function execute(
        Workspace $workspace, User $user, string $key, string $projectPublicId, #[\SensitiveParameter] string $bytes,
        string $source = VoiceAsset::SOURCE_API, ?string $sourceMessageKey = null,
    ): array {
        $metadata = $this->inspector->inspect($bytes);
        // A disabled or superseded policy cannot replay a successful upload
        // snapshot as though voice intake were still open.
        $this->consent->execute($workspace);

        /** @var array{VoiceAsset, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'project.attach_voice', $key,
            ['project' => $projectPublicId, 'sha256' => hash('sha256', $bytes)],
            function () use ($workspace, $user, $projectPublicId, $bytes, $metadata, $source, $sourceMessageKey): VoiceAsset {
                $this->consent->execute($workspace);
                $this->credit->execute($workspace);
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                if (! $project->state->isOpenForInput()) {
                    throw new DomainException('PROJECT_NOT_ACCEPTING_INPUT');
                }
                $mac = $this->store->contentMac($workspace, $bytes, PrivateAssetStore::KIND_VOICE);
                $existing = $this->voices->findByContent($project, $mac);
                if ($existing !== null) {
                    return $existing;
                }
                $publicId = Str::uuid()->toString();
                $storageKey = $this->store->put($workspace, $publicId, $bytes, PrivateAssetStore::KIND_VOICE);
                // Whatever undoes the row, here or in the idempotency record and commit, removes the object.
                // A failed cleanup is reported, never allowed to hide the original error.
                DB::afterRollBack(fn () => rescue(fn () => $this->store->delete($storageKey)));
                $voice = $this->voices->create($workspace, $project, $user, $publicId, [
                    'storage_key' => $storageKey,
                    'content_mac' => $mac,
                    'mime' => $metadata['mime'],
                    'size_bytes' => strlen($bytes),
                    'duration_ms' => $metadata['duration_ms'],
                    'source' => $source,
                    'source_message_key' => $sourceMessageKey,
                ]);
                $this->projects->bumpVersion($workspace, $project->id);

                return $voice;
            },
        );

        return $result;
    }
}
