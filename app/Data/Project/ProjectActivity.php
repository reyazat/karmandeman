<?php

declare(strict_types=1);

namespace App\Data\Project;

/**
 * What a batch holds and has cost, for the project list (FR-080): the input
 * files the seller sent, the products still in it and the credit its runs
 * were charged.
 */
final readonly class ProjectActivity
{
    public function __construct(
        public int $photos,
        public int $tables,
        public int $products,
        public int $creditsUsed,
    ) {}
}
