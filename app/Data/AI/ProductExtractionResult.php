<?php

declare(strict_types=1);

namespace App\Data\AI;

/**
 * A schema-valid extraction callback (ADR-032). Still untrusted: candidates
 * are drafts whose every value is only "suggested", and the asset ids are
 * checked against the run's own inputs before anything is stored.
 */
final readonly class ProductExtractionResult
{
    /**
     * @param  array{model_requests: int, input_tokens: int, output_tokens: int, provider: string, model: string}  $usage
     * @param  list<array{draft: array<string, mixed>, source_asset_ids: list<string>}>  $candidates
     */
    public function __construct(
        public string $runId,
        public bool $succeeded,
        public ?string $errorCode,
        public array $usage,
        public array $candidates,
    ) {}
}
