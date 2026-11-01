<?php

declare(strict_types=1);

namespace App\Http\Resources\Project;

use App\Data\Product\ParsedProductTable;
use App\Models\ProductTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A CSV with the mapping the seller confirms (ADR-040): the recognised
 * columns, the price unit the file names, a few rows as they would be
 * stored and every row that is skipped or loses its price, by line.
 *
 * @mixin ProductTable
 */
final class ProductTableResource extends JsonResource
{
    private const PREVIEW_ROWS = 5;

    private ?ParsedProductTable $parsed = null;

    public function withParsed(ParsedProductTable $parsed): self
    {
        $this->parsed = $parsed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parsed = $this->parsed;

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'row_count' => $this->row_count,
            'product_count' => $this->product_count,
            'columns' => $parsed?->columns,
            'price_unit' => $parsed?->headerUnit,
            'importable_rows' => $parsed === null ? null : count($parsed->importable()),
            'preview' => $parsed === null ? null : array_map(fn (array $row): array => [
                'line' => $row['line'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'price' => $row['price'],
            ], array_slice($parsed->importable(), 0, self::PREVIEW_ROWS)),
            'problems' => $parsed === null ? null : array_values(array_map(
                fn (array $row): array => ['line' => $row['line'], 'problem' => $row['problem']],
                array_filter($parsed->rows, fn (array $row): bool => $row['problem'] !== null),
            )),
            'imported_at' => $this->imported_at?->toIso8601String(),
        ];
    }
}
