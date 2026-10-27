<?php

declare(strict_types=1);

namespace App\Http\Requests\Brand;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBrandProfileRequest extends FormRequest
{
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
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency_display_unit' => ['sometimes', 'string', 'in:toman,irr'],
            'tone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'target_audience' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'color_palette' => ['sometimes', 'nullable', 'array', 'max:10'],
            'color_palette.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'contact_channels' => ['sometimes', 'nullable', 'array', 'max:10'],
            'contact_channels.*' => ['string', 'max:255'],
            'catalog_price_visibility_default' => ['sometimes', 'boolean'],
            'default_outputs' => ['sometimes', 'nullable', 'array', 'max:5'],
            'default_outputs.*' => ['string', 'distinct', 'in:woocommerce_csv,pdf_catalog,json,online_catalog,zip'],
            'required_product_fields' => ['sometimes', 'nullable', 'array', 'max:20'],
            'required_product_fields.*' => ['string', 'distinct', 'max:64'],
        ];
    }

    /**
     * Only the whitelisted brand fields — never workspace_id from the body.
     *
     * @return array<string, mixed>
     */
    public function brandAttributes(): array
    {
        return $this->safe()->except(['idempotency_key', 'expected_version']);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
