<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProjectState;
use App\Models\CreditQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\CreditQuoteRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\AI\AIWorkflowService;
use App\Services\ProductIntake\ExtractionInputSelector;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Prices the extraction run the batch's new photos would need (FR-072,
 * ADR-039): the photos, credits per photo, total and expiry, bound to the
 * batch version. Nothing is held yet; finish-input with the quote holds it.
 */
final readonly class CreateExtractionQuoteAction
{
    public const LINE_TYPE = 'product_extraction_photo';

    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private CreditQuoteRepositoryInterface $quotes,
        private WalletRepositoryInterface $wallets,
        private ExtractionInputSelector $inputs,
        private AIWorkflowService $ai,
    ) {}

    /**
     * @return array{CreditQuote, bool, int} the quote, whether it was replayed, and the credit available now
     */
    public function execute(Workspace $workspace, User $user, string $key, string $projectPublicId, int $version): array
    {
        /** @var array{CreditQuote, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'project.extraction_quote', $key, ['project' => $projectPublicId, 'version' => $version],
            function () use ($workspace, $user, $projectPublicId, $version): CreditQuote {
                $this->consent->execute($workspace);
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                // Open until approval: a batch in review prices its new photos too (ADR-041).
                if ($project->state !== ProjectState::CollectingInput && $project->state !== ProjectState::ReviewRequired) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }
                if ($project->version !== $version) {
                    throw new DomainException('PROJECT_VERSION_CONFLICT');
                }
                $photos = $this->ai->extractionEnabled() ? $this->inputs->photos($workspace, $project) : [];
                // Nothing to pay for: finish-input takes the manual path without a quote.
                if ($photos === []) {
                    throw new DomainException('EXTRACTION_NOT_NEEDED');
                }

                $perPhoto = (int) config('karmandeman.credits.extraction_per_photo');

                return $this->quotes->create(
                    $project, $user, CreditQuote::KIND_PRODUCT_EXTRACTION, (string) config('karmandeman.credits.tariff_version'),
                    array_map(fn ($asset): string => $asset->public_id, $photos),
                    [['type' => self::LINE_TYPE, 'quantity' => count($photos), 'unit_credits' => $perPhoto, 'credits' => count($photos) * $perPhoto]],
                    CarbonImmutable::now()->addMinutes((int) config('karmandeman.credits.quote_ttl_minutes')),
                );
            },
        );

        // A replay answers the quote as it is now, accepted or not, not as first created.
        $quote = $result[1] ? ($this->quotes->findPublicForWorkspace($workspace, $result[0]->public_id) ?? $result[0]) : $result[0];

        return [$quote, $result[1], $this->wallets->forWorkspace($workspace)?->available() ?? 0];
    }
}
