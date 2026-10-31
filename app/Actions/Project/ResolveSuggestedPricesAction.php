<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Product\ReviseProductDraftAction;
use App\Enums\ProjectState;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductDraftNormalizer;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use App\Services\ProductIntake\SuggestedPriceGroup;
use App\Services\Telegram\TelegramProductCard;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * The answer to one grouped price question (FR-032, ADR-035): every price it
 * listed is confirmed, or every one is dropped to unknown ("without price for
 * now"). The answer names the batch version shown, and the rows are listed
 * again at that version under the workspace lock, so it acts on exactly the
 * products and prices shown. Only the price changes; each product gets one
 * new revision, and either all of them are written or none.
 */
final readonly class ResolveSuggestedPricesAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private SuggestedPriceGroup $prices,
        private ProductDraftNormalizer $normalizer,
        private ProjectSnapshotSigner $signer,
        private ReviseProductDraftAction $revise,
        private TelegramProductCard $card,
    ) {}

    /**
     * @return array{Project, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $projectPublicId, int $version, bool $confirm): array
    {
        /** @var array{Project, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'project.resolve_prices', $key,
            ['project' => $projectPublicId, 'version' => $version, 'confirm' => $confirm],
            function () use ($workspace, $user, $key, $projectPublicId, $version, $confirm): Project {
                $this->consent->execute($workspace);
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                if ($project->state !== ProjectState::ReviewRequired) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }
                if ($project->version !== $version) {
                    throw new DomainException('PROJECT_VERSION_CONFLICT');
                }
                $rows = $this->prices->list($workspace, $project);
                // The question is asked only for two or more rows the seller can tell apart (TelegramBatchReview).
                $labels = array_map(fn (array $row): string => $this->card->priceLabel($row[1]), $rows);
                if (count($rows) < 2 || count(array_unique($labels)) !== count($labels)) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }

                foreach ($rows as [$product, $revision]) {
                    // A stored payload that does not match its own MAC is never copied into a fresh one.
                    if (! hash_equals((string) $revision->payload_mac, $this->signer->payloadMac($workspace, $revision))) {
                        throw new DomainException('PRODUCT_PAYLOAD_UNTRUSTED');
                    }
                    $draft = $this->normalizer->draftFrom($revision->payload);
                    if ($confirm) {
                        $draft['pricing']['status'] = 'confirmed';
                    } else {
                        unset($draft['pricing']);
                    }
                    $this->revise->execute($workspace, $user, "{$key}:{$product->public_id}", $product->public_id, $revision->revision, $draft);
                }

                return $this->projects->findForWorkspace($workspace, $project->id)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
            },
        );

        return $result;
    }
}
