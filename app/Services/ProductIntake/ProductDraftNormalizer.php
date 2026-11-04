<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use Illuminate\Support\Str;
use LogicException;

/**
 * Builds the stored product payload (record schema 1.0, ADR-016) from a
 * validated draft. Provenance is set here, never taken from the client: a
 * manual draft can only claim `manual_override`, and a correction keeps only
 * the stored provenance of values it left unchanged (FR-030, FR-033). Prices are
 * stored as integer rial; toman input is multiplied by exactly 10 (PRD E04).
 * Text is normalized; changed raw input is kept under `originals` (ADR-017).
 */
final readonly class ProductDraftNormalizer
{
    public const SCHEMA_VERSION = '1.0';

    public const SOURCE_MANUAL = 'manual_override';

    /** Candidate of an n8n extraction run; every value is only suggested (ADR-032). */
    public const SOURCE_AI = 'ai_extraction';

    /** A row of the seller's CSV, mapped with the seller's confirmation (ADR-040). */
    public const SOURCE_TABLE = 'table_import';

    private const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_AI, self::SOURCE_TABLE];

    private const TEXT_FIELDS = ['name', 'sku', 'description_fa'];

    public function __construct(private PersianTextNormalizer $text) {}

    /**
     * @param  array<string, mixed>  $draft  validated StoreProductDraftRequest input
     * @param  list<string>  $sourceAssetIds  batch photos an extracted value came from (FR-030)
     * @param  array{table_id: string, line: int}|null  $tableRow  the CSV row an imported value came from (FR-030)
     * @return array<string, mixed>
     */
    public function normalize(array $draft, string $source = self::SOURCE_MANUAL, array $sourceAssetIds = [], ?array $tableRow = null): array
    {
        $payload = ['schema_version' => self::SCHEMA_VERSION];
        $evidence = [];
        $originals = [];
        $cite = fn (string $field): array => $this->citation($field, $source, $sourceAssetIds, $tableRow);

        foreach (self::TEXT_FIELDS as $field) {
            $payload[$field] = $this->field("{$field}.value", $draft[$field] ?? null, $originals);
            if ($payload[$field]['value'] !== null) {
                $evidence[] = $cite($field);
            }
        }

        $pricing = $draft['pricing'] ?? null;
        $price = $pricing['regular_price'] ?? null;
        $unit = $pricing['input_unit'] ?? null;
        if ($price !== null && ! in_array($unit, ['IRR', 'IRT'], true)) {
            throw new LogicException('A price needs a validated currency unit.');
        }
        $payload['pricing'] = [
            'minor_currency' => 'IRR',
            'regular_price_irr' => $price === null ? null : ($unit === 'IRT' ? (int) $price * 10 : (int) $price),
            'display_unit' => match ($unit) {
                'IRT' => 'toman',
                'IRR' => 'rial',
                default => null,
            },
            'source' => $price === null ? null : $source,
            'status' => $price === null ? 'unknown' : $this->status($pricing['status'] ?? null),
        ];
        if ($price !== null) {
            $evidence[] = $cite('pricing.regular_price_irr');
        }

        $payload['attributes'] = [];
        foreach (array_values($draft['attributes'] ?? []) as $index => $attribute) {
            $values = $attribute['values'] ?? null;
            $payload['attributes'][] = [
                'name' => $this->clean("attributes.{$index}.name", (string) $attribute['name'], $originals),
                'values' => $values === null ? null : $this->list("attributes.{$index}.values", $values, $originals),
                'status' => $values === null ? 'unknown' : $this->status($attribute['status'] ?? null),
            ];
            if ($values !== null) {
                $evidence[] = $cite("attributes.{$index}");
            }
        }

        $categories = $draft['category_path'] ?? null;
        $path = $categories['value'] ?? null;
        $payload['category_path'] = [
            'value' => $path === null ? null : $this->list('category_path.value', $path, $originals),
            'status' => $path === null ? 'unknown' : $this->status($categories['status'] ?? null),
        ];
        if ($path !== null) {
            $evidence[] = $cite('category_path');
        }

        // Ids of private assets of the same batch, checked by ProductImageGuard (ADR-023).
        $payload['image_asset_ids'] = self::imageAssetIds($draft);
        if ($payload['image_asset_ids'] !== []) {
            $evidence[] = $cite('image_asset_ids');
        }
        $payload['evidence'] = $evidence;
        $payload['originals'] = $originals;

        return $payload;
    }

    /**
     * The draft a stored payload was built from, with the raw text kept under
     * `originals`: normalizing it again gives the same payload. A correction that
     * changes one field starts from it, so the other fields stay as they are (ADR-029).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function draftFrom(array $payload): array
    {
        $originals = $payload['originals'] ?? [];
        $raw = fn (string $path, string $value): string => $originals[$path] ?? $value;
        $draft = [];
        foreach (self::TEXT_FIELDS as $field) {
            if ($payload[$field]['value'] !== null) {
                $draft[$field] = ['value' => $raw("{$field}.value", $payload[$field]['value']), 'status' => $payload[$field]['status']];
            }
        }
        $pricing = $payload['pricing'];
        if ($pricing['regular_price_irr'] !== null) {
            // Toman input was multiplied by exactly 10, so dividing back is exact.
            $toman = $pricing['display_unit'] === 'toman';
            $draft['pricing'] = [
                'regular_price' => $toman ? intdiv((int) $pricing['regular_price_irr'], 10) : (int) $pricing['regular_price_irr'],
                'input_unit' => $toman ? 'IRT' : 'IRR',
                'status' => $pricing['status'],
            ];
        }
        $draft['attributes'] = [];
        foreach ($payload['attributes'] as $index => $attribute) {
            $values = $attribute['values'];
            $draft['attributes'][] = [
                'name' => $raw("attributes.{$index}.name", $attribute['name']),
                'values' => $values === null ? null : array_map(
                    fn (int $i): string => $raw("attributes.{$index}.values.{$i}", $values[$i]), array_keys($values),
                ),
                'status' => $attribute['status'],
            ];
        }
        $path = $payload['category_path']['value'];
        if ($path !== null) {
            $draft['category_path'] = [
                'value' => array_map(fn (int $i): string => $raw("category_path.value.{$i}", $path[$i]), array_keys($path)),
                'status' => $payload['category_path']['status'],
            ];
        }
        $draft['image_asset_ids'] = $payload['image_asset_ids'];

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    public static function imageAssetIds(array $draft): array
    {
        return array_map(fn (mixed $id): string => strtolower((string) $id), array_values($draft['image_asset_ids'] ?? []));
    }

    /**
     * Review flags stored beside the encrypted payload so the queue and the
     * batch summary are counted in SQL (FR-031, FR-040, ADR-017).
     *
     * @param  array<string, mixed>  $payload
     * @return array{needs_confirmation: bool, price_missing: bool, image_missing: bool}
     */
    public function reviewFlags(array $payload): array
    {
        return [
            'needs_confirmation' => $this->fieldsRequiringConfirmation($payload) !== [],
            'price_missing' => $payload['pricing']['regular_price_irr'] === null,
            'image_missing' => $payload['image_asset_ids'] === [],
        ];
    }

    /**
     * Paths that block Gate A: anything still only suggested, and a missing name.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function fieldsRequiringConfirmation(array $payload): array
    {
        $fields = [];
        foreach (self::TEXT_FIELDS as $field) {
            if ($payload[$field]['status'] === 'suggested') {
                $fields[] = $field;
            }
        }
        if ($payload['name']['value'] === null) {
            $fields[] = 'name';
        }
        if ($payload['pricing']['status'] === 'suggested') {
            $fields[] = 'pricing.regular_price_irr';
        }
        foreach ($payload['attributes'] as $index => $attribute) {
            if ($attribute['status'] === 'suggested') {
                $fields[] = "attributes.{$index}";
            }
        }
        if ($payload['category_path']['status'] === 'suggested') {
            $fields[] = 'category_path';
        }

        return array_values(array_unique($fields));
    }

    /**
     * Keeps the provenance of every value a new revision leaves unchanged
     * (FR-030, ADR-034): confirming a suggested value, or correcting another
     * field, keeps it cited to the photos it came from. Only a changed value
     * becomes `manual_override`. An attribute is matched by its name and values,
     * not its position, so removing one keeps the others' citations (ADR-051).
     *
     * @param  array<string, mixed>  $payload  normalized from a manual draft
     * @param  array<string, mixed>  $previous  payload of the revision it replaces
     * @return array<string, mixed>
     */
    public function carryEvidence(array $payload, array $previous): array
    {
        $before = [];
        foreach ($previous['evidence'] ?? [] as $entry) {
            // Rebuilt from known parts only: nothing else of a stored entry is carried.
            $ids = $entry['source_asset_ids'] ?? [];
            $table = $entry['source_table_id'] ?? null;
            $line = $entry['source_line'] ?? null;
            $tableRow = is_string($table) && Str::isUuid($table) && is_int($line) && $line > 0 ? ['table_id' => $table, 'line' => $line] : null;
            if (! is_string($entry['field'] ?? null) || ! in_array($entry['source'] ?? null, self::SOURCES, true)
                || ! is_array($ids) || ! array_is_list($ids) || array_filter($ids, fn (mixed $id): bool => ! is_string($id) || ! Str::isUuid($id)) !== []
                || ($entry['source'] === self::SOURCE_TABLE) !== ($tableRow !== null)) {
                continue;
            }
            /** @var list<string> $ids */
            $before[$entry['field']] = $this->citation($entry['field'], $entry['source'], $ids, $tableRow);
        }
        foreach ($payload['evidence'] as $index => $entry) {
            $field = $entry['field'];
            $value = $this->evidenceValue($payload, $field);
            $candidates = str_starts_with($field, 'attributes.')
                ? array_filter(array_keys($before), fn (string $key): bool => str_starts_with($key, 'attributes.'))
                : [$field];
            foreach ($candidates as $key) {
                if (isset($before[$key]) && $value === $this->evidenceValue($previous, $key)) {
                    $payload['evidence'][$index] = ['field' => $field] + $before[$key];

                    break;
                }
            }
            if ($field === 'pricing.regular_price_irr') {
                $payload['pricing']['source'] = $payload['evidence'][$index]['source'];
            }
        }

        return $payload;
    }

    /**
     * One evidence entry: its source and the photos or CSV row it came from.
     *
     * @param  list<string>  $sourceAssetIds
     * @param  array{table_id: string, line: int}|null  $tableRow
     * @return array<string, mixed>
     */
    private function citation(string $field, string $source, array $sourceAssetIds, ?array $tableRow): array
    {
        $entry = ['field' => $field, 'source' => $source];
        if ($sourceAssetIds !== []) {
            $entry['source_asset_ids'] = $sourceAssetIds;
        }
        if ($tableRow !== null) {
            $entry['source_table_id'] = $tableRow['table_id'];
            $entry['source_line'] = $tableRow['line'];
        }

        return $entry;
    }

    /** @param  array<string, mixed>  $payload */
    private function evidenceValue(array $payload, string $field): mixed
    {
        if (str_starts_with($field, 'attributes.')) {
            $attribute = $payload['attributes'][(int) substr($field, 11)] ?? null;

            return $attribute === null ? null : [$attribute['name'], $attribute['values']];
        }

        return match ($field) {
            // A changed display unit is a correction too, though the rial amount is the same.
            'pricing.regular_price_irr' => [$payload['pricing']['regular_price_irr'] ?? null, $payload['pricing']['display_unit'] ?? null],
            'category_path' => $payload['category_path']['value'] ?? null,
            'image_asset_ids' => $payload['image_asset_ids'] ?? null,
            default => $payload[$field]['value'] ?? null,
        };
    }

    /**
     * @param  array<string, string>  $originals
     * @return array{value: string|null, status: string}
     */
    private function field(string $path, mixed $input, array &$originals): array
    {
        $value = is_array($input) ? ($input['value'] ?? null) : null;

        return [
            'value' => $value === null ? null : $this->clean($path, (string) $value, $originals),
            'status' => $value === null ? 'unknown' : $this->status($input['status'] ?? null),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, string>  $originals
     * @return list<string>
     */
    private function list(string $path, array $values, array &$originals): array
    {
        $clean = [];
        foreach (array_values($values) as $index => $value) {
            $clean[] = $this->clean("{$path}.{$index}", (string) $value, $originals);
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $originals
     */
    private function clean(string $path, string $raw, array &$originals): string
    {
        $value = match ($path) {
            'sku.value' => $this->text->sku($raw),
            'description_fa.value' => $this->text->text($raw),
            default => $this->text->line($raw),
        };
        if ($value !== $raw) {
            $originals[$path] = $raw;
        }

        return $value;
    }

    private function status(mixed $status): string
    {
        return in_array($status, ['confirmed', 'suggested'], true) ? $status : 'suggested';
    }
}
