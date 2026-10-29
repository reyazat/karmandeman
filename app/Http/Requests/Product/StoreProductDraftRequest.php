<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Services\ProductIntake\PersianTextNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Manual product draft, used both to create (POST) and to revise (PATCH).
 * Provenance and evidence are never read from the body (ADR-016).
 */
final class StoreProductDraftRequest extends FormRequest
{
    private const STATUS = 'in:confirmed,suggested,unknown';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'expected_revision' => [$this->isMethod('patch') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'name' => ['sometimes', 'nullable', 'array:value,status'],
            'name.value' => ['nullable', 'string', 'max:200'],
            'name.status' => ['nullable', 'string', self::STATUS],
            'sku' => ['sometimes', 'nullable', 'array:value,status'],
            'sku.value' => ['nullable', 'string', 'max:64', 'regex:/^[^\p{C}]+$/u'],
            'sku.status' => ['nullable', 'string', self::STATUS],
            'description_fa' => ['sometimes', 'nullable', 'array:value,status'],
            'description_fa.value' => ['nullable', 'string', 'max:5000'],
            'description_fa.status' => ['nullable', 'string', self::STATUS],
            'pricing' => ['sometimes', 'nullable', 'array:regular_price,input_unit,status'],
            // Unknown price is null, never 0; a price without its unit is refused (PRD E04).
            'pricing.regular_price' => ['nullable', 'integer', 'min:1', 'max:999999999999'],
            'pricing.input_unit' => ['nullable', 'required_with:pricing.regular_price', 'string', 'in:IRR,IRT'],
            'pricing.status' => ['nullable', 'string', self::STATUS],
            'attributes' => ['sometimes', 'nullable', 'array', 'max:30'],
            'attributes.*' => ['array:name,values,status'],
            'attributes.*.name' => ['required', 'string', 'max:100'],
            'attributes.*.values' => ['nullable', 'array', 'min:1', 'max:20'],
            'attributes.*.values.*' => ['string', 'max:200'],
            'attributes.*.status' => ['nullable', 'string', self::STATUS],
            'category_path' => ['sometimes', 'nullable', 'array:value,status'],
            'category_path.value' => ['nullable', 'array', 'min:1', 'max:5'],
            'category_path.value.*' => ['string', 'max:100'],
            'category_path.status' => ['nullable', 'string', self::STATUS],
            // Photos of this batch, first is the main image (ADR-023). Checked against the batch in the Action.
            'image_asset_ids' => ['sometimes', 'nullable', 'array', 'list', 'max:10'],
            'image_asset_ids.*' => ['string', 'uuid', 'distinct:ignore_case'],
        ];
    }

    /**
     * A value and its status must agree: "unknown" carries no value, and a
     * known value cannot be marked unknown. Text that normalizes to nothing
     * (only ZWNJ or tatweel, for example) is refused (ADR-017).
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $text = app(PersianTextNormalizer::class);
            foreach ($this->textInputs() as $path => $value) {
                if (! mb_check_encoding($value, 'UTF-8')) {
                    $validator->errors()->add($path, "The {$path} is not valid UTF-8.");
                } elseif ($text->line($value) === '') {
                    $validator->errors()->add($path, "The {$path} is empty after normalization.");
                }
            }
        }, function (Validator $validator): void {
            $pairs = [
                'name' => ['name.value', 'name.status'],
                'sku' => ['sku.value', 'sku.status'],
                'description_fa' => ['description_fa.value', 'description_fa.status'],
                'pricing' => ['pricing.regular_price', 'pricing.status'],
                'category_path' => ['category_path.value', 'category_path.status'],
            ];
            foreach ((array) $this->input('attributes', []) as $index => $_) {
                $pairs["attributes.{$index}"] = ["attributes.{$index}.values", "attributes.{$index}.status"];
            }
            foreach ($pairs as $field => [$valuePath, $statusPath]) {
                $value = $this->input($valuePath);
                $status = $this->input($statusPath);
                if (($value === null && in_array($status, ['confirmed', 'suggested'], true))
                    || ($value !== null && $status === 'unknown')) {
                    $validator->errors()->add($statusPath, "The {$field} status does not match its value.");
                }
            }
        }];
    }

    /**
     * Every free-text value of the draft, keyed by its input path.
     *
     * @return array<string, string>
     */
    private function textInputs(): array
    {
        $texts = [];
        foreach (['name', 'sku', 'description_fa'] as $field) {
            $texts["{$field}.value"] = $this->input("{$field}.value");
        }
        foreach ((array) $this->input('attributes', []) as $index => $attribute) {
            $texts["attributes.{$index}.name"] = $attribute['name'] ?? null;
            foreach ((array) ($attribute['values'] ?? []) as $position => $value) {
                $texts["attributes.{$index}.values.{$position}"] = $value;
            }
        }
        foreach ((array) $this->input('category_path.value', []) as $position => $value) {
            $texts["category_path.value.{$position}"] = $value;
        }

        return array_filter($texts, 'is_string');
    }

    /**
     * @return array<string, mixed>
     */
    public function draft(): array
    {
        return $this->safe()->except(['idempotency_key', 'expected_revision']);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
