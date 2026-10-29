<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use LogicException;

/**
 * Builds the stored product payload (record schema 1.0, ADR-016) from a
 * validated draft. Provenance is set here, never taken from the client: a
 * manual draft can only claim `manual_override` (FR-030, FR-033). Prices are
 * stored as integer rial; toman input is multiplied by exactly 10 (PRD E04).
 * Text is normalized; changed raw input is kept under `originals` (ADR-017).
 */
final readonly class ProductDraftNormalizer
{
    public const SCHEMA_VERSION = '1.0';

    public const SOURCE_MANUAL = 'manual_override';

    private const TEXT_FIELDS = ['name', 'sku', 'description_fa'];

    public function __construct(private PersianTextNormalizer $text) {}

    /**
     * @param  array<string, mixed>  $draft  validated StoreProductDraftRequest input
     * @return array<string, mixed>
     */
    public function normalize(array $draft, string $source = self::SOURCE_MANUAL): array
    {
        $payload = ['schema_version' => self::SCHEMA_VERSION];
        $evidence = [];
        $originals = [];

        foreach (self::TEXT_FIELDS as $field) {
            $payload[$field] = $this->field("{$field}.value", $draft[$field] ?? null, $originals);
            if ($payload[$field]['value'] !== null) {
                $evidence[] = ['field' => $field, 'source' => $source];
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
            $evidence[] = ['field' => 'pricing.regular_price_irr', 'source' => $source];
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
                $evidence[] = ['field' => "attributes.{$index}", 'source' => $source];
            }
        }

        $categories = $draft['category_path'] ?? null;
        $path = $categories['value'] ?? null;
        $payload['category_path'] = [
            'value' => $path === null ? null : $this->list('category_path.value', $path, $originals),
            'status' => $path === null ? 'unknown' : $this->status($categories['status'] ?? null),
        ];
        if ($path !== null) {
            $evidence[] = ['field' => 'category_path', 'source' => $source];
        }

        // Ids of private assets of the same batch, checked by ProductImageGuard (ADR-023).
        $payload['image_asset_ids'] = self::imageAssetIds($draft);
        if ($payload['image_asset_ids'] !== []) {
            $evidence[] = ['field' => 'image_asset_ids', 'source' => $source];
        }
        $payload['evidence'] = $evidence;
        $payload['originals'] = $originals;

        return $payload;
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
