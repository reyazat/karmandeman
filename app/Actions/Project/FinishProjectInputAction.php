<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Wallet\ReserveCreditsAction;
use App\Enums\ProjectState;
use App\Jobs\AI\DispatchProductExtraction;
use App\Models\AiWorkflowRun;
use App\Models\Asset;
use App\Models\CreditLedgerEntry;
use App\Models\CreditQuote;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\CreditQuoteRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\AI\AIWorkflowService;
use App\Services\ProductIntake\ExtractionInputSelector;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Close the batch input (PRD §6, ADR-018). With extraction on, photos no
 * product has claimed go to one n8n run and the batch waits in `extracting`
 * (ADR-032). That run is paid work: it starts only from a quote the seller
 * accepted, which holds its credit first (FR-072, ADR-039). Otherwise the
 * manual path goes straight to review. A batch in review is open until
 * approval: new photos sent there start a run the same way (ADR-041).
 */
final readonly class FinishProjectInputAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private AiWorkflowRunRepositoryInterface $runs,
        private CreditQuoteRepositoryInterface $quotes,
        private ExtractionInputSelector $inputs,
        private ReserveCreditsAction $reserve,
        private AIWorkflowService $ai,
    ) {}

    /**
     * @return array{Project, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $projectPublicId, int $version, ?string $quotePublicId = null): array
    {
        /** @var array{Project, bool} $result */
        $result = $this->idempotency->execute(
            // The quote joins the fingerprint only when sent, so retries recorded before quotes still replay.
            $workspace, $user->id, 'project.finish_input', $key, ['project' => $projectPublicId, 'version' => $version] + ($quotePublicId === null ? [] : ['quote' => $quotePublicId]),
            function () use ($workspace, $user, $projectPublicId, $version, $quotePublicId): Project {
                $this->consent->execute($workspace);
                // The workspace row lock taken by the idempotency wrapper serializes this read.
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                // A batch in review is still open (ADR-041): photos sent since its run go to a new one.
                if ($project->state !== ProjectState::CollectingInput && $project->state !== ProjectState::ReviewRequired) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }
                if ($project->version !== $version) {
                    throw new DomainException('PROJECT_VERSION_CONFLICT');
                }
                $photos = $this->ai->extractionEnabled($workspace) ? $this->inputs->photos($workspace, $project) : [];
                if ($photos !== []) {
                    $quote = $this->acceptedQuote($workspace, $project, $quotePublicId, $photos);

                    return $this->startExtraction($workspace, $project, $user, $quote);
                }
                // A quote for a run that is no longer needed or allowed is never taken as accepted.
                if ($quotePublicId !== null) {
                    throw new DomainException('QUOTE_STALE');
                }
                // In review, with nothing new to read, the batch is already where /done would take it.
                if ($project->state === ProjectState::ReviewRequired) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }
                if ($this->products->activeForProject($workspace, $project)->isEmpty()) {
                    throw new DomainException('PROJECT_HAS_NO_PRODUCTS');
                }

                return $this->projects->advance($project, ProjectState::ReviewRequired);
            },
        );

        return $result;
    }

    /**
     * The quote the seller accepted for exactly these photos of this batch version.
     *
     * @param  list<Asset>  $photos
     */
    private function acceptedQuote(Workspace $workspace, Project $project, ?string $quotePublicId, array $photos): CreditQuote
    {
        if ($quotePublicId === null) {
            throw new DomainException('EXTRACTION_QUOTE_REQUIRED');
        }
        $quote = $this->quotes->findPublicForWorkspace($workspace, $quotePublicId);
        if ($quote === null || $quote->project_id !== $project->id || $quote->kind !== CreditQuote::KIND_PRODUCT_EXTRACTION) {
            throw new DomainException('QUOTE_NOT_FOUND');
        }
        if ($quote->accepted_at !== null) {
            throw new DomainException('QUOTE_ALREADY_USED');
        }
        if ($quote->expires_at->isPast()) {
            throw new DomainException('QUOTE_EXPIRED');
        }
        // A new or used photo since the quote changes what would be read. A Gate A press or an
        // edit of another product does not: the price is for these photos only (ADR-041).
        if ($quote->input_asset_ids !== array_map(fn (Asset $asset): string => $asset->public_id, $photos)) {
            throw new DomainException('QUOTE_STALE');
        }

        return $quote;
    }

    private function startExtraction(Workspace $workspace, Project $project, User $user, CreditQuote $quote): Project
    {
        $project = $this->projects->advance($project, ProjectState::Extracting);
        $run = $this->runs->create(
            $project, $user, AiWorkflowRun::WORKFLOW_PRODUCT_EXTRACTION, (string) config('karmandeman.ai.workflow_version'),
            $quote->input_asset_ids,
            CarbonImmutable::now()->addMinutes((int) config('karmandeman.ai.run_timeout_minutes')),
        );
        // The quote's cap is held before n8n hears of the run; without the credit the
        // whole transaction, run included, rolls back (FR-070).
        $hold = $this->reserve->execute(
            $workspace, $quote->capCredits(), "credit:reserve:quote:{$quote->id}", CreditLedgerEntry::RELATED_RUN, $run->id, CreditQuote::KIND_PRODUCT_EXTRACTION,
        );
        $this->runs->attachCredit($run, $quote, $hold);
        $this->quotes->markAccepted($quote, CarbonImmutable::now());
        // Inserted in this transaction like the Telegram outbox (ADR-008): no run without its start.
        DispatchProductExtraction::dispatch($run->id)->onConnection('ai')->onQueue('ai')->beforeCommit();

        return $project;
    }
}
