<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProjectState;
use App\Models\Asset;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\Storage\PrivateAssetStore;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * A product photo into an open batch (FR-025, ADR-022). The file is checked
 * to be a real image, encrypted under the workspace key and stored privately.
 * The same bytes twice in one batch return the first asset.
 */
final readonly class AttachProjectAssetAction
{
    private const OPEN_PROJECT_STATES = [
        ProjectState::CollectingInput, ProjectState::NeedsClarification, ProjectState::ReviewRequired,
    ];

    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private AssetRepositoryInterface $assets,
        private PrivateAssetStore $store,
    ) {}

    /**
     * @return array{Asset, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $projectPublicId, #[\SensitiveParameter] string $bytes): array
    {
        $image = $this->inspect($bytes);

        /** @var array{Asset, bool} $result */
        $result = $this->idempotency->execute(
            // The fingerprint is MACed by the wrapper; the plain digest is never stored.
            $workspace, $user->id, 'project.attach_asset', $key, ['project' => $projectPublicId, 'sha256' => hash('sha256', $bytes)],
            function () use ($workspace, $user, $projectPublicId, $bytes, $image): Asset {
                $this->consent->execute($workspace);
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                if (! in_array($project->state, self::OPEN_PROJECT_STATES, true)) {
                    throw new DomainException('PROJECT_NOT_ACCEPTING_INPUT');
                }

                $contentMac = $this->store->contentMac($workspace, $bytes);
                $existing = $this->assets->findByContent($project, $contentMac);
                if ($existing !== null) {
                    return $existing;
                }

                $publicId = Str::uuid()->toString();
                $storageKey = $this->store->put($workspace, $publicId, $bytes);
                try {
                    $asset = $this->assets->create($workspace, $project, $user, $publicId, [
                        'storage_key' => $storageKey,
                        'content_mac' => $contentMac,
                        'mime' => $image['mime'],
                        'size_bytes' => strlen($bytes),
                        'width' => $image['width'],
                        'height' => $image['height'],
                        'source' => Asset::SOURCE_API_UPLOAD,
                    ]);
                    $this->projects->bumpVersion($workspace, $project->id);
                } catch (Throwable $e) {
                    // A failed cleanup is reported, never allowed to hide the original error.
                    rescue(fn () => $this->store->delete($storageKey));

                    throw $e;
                }

                return $asset;
            },
        );

        return $result;
    }

    /**
     * The bytes themselves decide the type, never the name or the client's MIME.
     *
     * @return array{mime: string, width: int, height: int}
     */
    private function inspect(#[\SensitiveParameter] string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info === false ? null : (self::IMAGE_TYPES[$info[2]] ?? null);
        if ($info === false || $mime === null || $info[0] < 1 || $info[1] < 1) {
            throw new DomainException('ASSET_UNSUPPORTED');
        }
        if ((int) config('karmandeman.assets.max_pixels') < $info[0] * $info[1]) {
            throw new DomainException('ASSET_TOO_LARGE');
        }

        return ['mime' => $mime, 'width' => $info[0], 'height' => $info[1]];
    }
}
