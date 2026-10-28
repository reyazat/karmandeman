<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use LogicException;

/**
 * Builds the stored product payload (record schema 1.0, ADR-016) from a
 * validated draft. Provenance is set here, never taken from the client: a
 * manual draft can only claim `manual_override` (FR-030, FR-033). Prices are
 * stored as integer rial; toman input is multiplied by exactly 10 (PRD E04).
 */
final class ProductDraftNormalizer
{
    public const SCHEMA_VERSION = '1.0';

    public const SOURCE_MANUAL = 'manual_override';

    private const TEXT_FIELDS = ['name', 'sku', 'description_fa'];

    /**
     * @param  array<string, mixed>  $draft  validated StoreProductDraftRequest input
     * @return array<string, mixed>
     */
    public function normalize(array $draft, string $source = self::SOURCE_MANUAL): array
    {
        $payload = ['schema_version' => self::SCHEMA_VERSION];
        $evidence = [];

        foreach (self::TEXT_FIELDS as $field) {
            $payload[$field] = $this->field($draft[$field] ?? null);
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
                'name' => (string) $attribute['name'],
                'values' => $values === null ? null : array_values(array_map('strval', $values)),
                'status' => $values === null ? 'unknown' : $this->status($attribute['status'] ?? null),
            ];
            if ($values !== null) {
                $evidence[] = ['field' => "attributes.{$index}", 'source' => $source];
            }
        }

        $categories = $draft['category_path'] ?? null;
        $path = $categories['value'] ?? null;
        $payload['category_path'] = [
            'value' => $path === null ? null : array_values(array_map('strval', $path)),
            'status' => $path === null ? 'unknown' : $this->status($categories['status'] ?? null),
        ];
        if ($path !== null) {
            $evidence[] = ['field' => 'category_path', 'source' => $source];
        }

        // Asset intake is a later slice; the key is part of the schema already.
        $payload['image_asset_ids'] = [];
        $payload['evidence'] = $evidence;

        return $payload;
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
     * @return array{value: string|null, status: string}
     */
    private function field(mixed $input): array
    {
        $value = is_array($input) ? ($input['value'] ?? null) : null;

        return [
            'value' => $value === null ? null : (string) $value,
            'status' => $value === null ? 'unknown' : $this->status($input['status'] ?? null),
        ];
    }

    private function status(mixed $status): string
    {
        return in_array($status, ['confirmed', 'suggested'], true) ? $status : 'suggested';
    }
}
