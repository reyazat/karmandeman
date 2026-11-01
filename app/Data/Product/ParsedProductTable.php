<?php

declare(strict_types=1);

namespace App\Data\Product;

/**
 * A seller's CSV read into product drafts (ADR-040). Each row keeps its line
 * number in the file, so every stored value can cite it (FR-030). Prices are
 * kept apart: their unit is known only once the seller confirms it.
 */
final readonly class ParsedProductTable
{
    /**
     * @param  list<array{index: int, header: string, field: string}>  $columns  field is name, sku, price, description, category, attribute or ignored
     * @param  'IRT'|'IRR'|null  $headerUnit  the unit the price header or the price cells name, if any
     * @param  list<array{line: int, draft: array<string, mixed>|null, price: int|null, problem: string|null, name: string|null, sku: string|null}>  $rows  a row without a draft is skipped; name and sku are as they will be stored
     */
    public function __construct(
        public array $columns,
        public ?string $headerUnit,
        public array $rows,
    ) {}

    /** @return list<array{line: int, draft: array<string, mixed>|null, price: int|null, problem: string|null, name: string|null, sku: string|null}> */
    public function importable(): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => $row['draft'] !== null));
    }

    public function hasPrices(): bool
    {
        foreach ($this->importable() as $row) {
            if ($row['price'] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> problem code => number of rows */
    public function problemCounts(): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            if ($row['problem'] !== null) {
                $counts[$row['problem']] = ($counts[$row['problem']] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
