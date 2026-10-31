<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Services\ProductIntake\ProductDraftRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Manual product draft, used both to create (POST) and to revise (PATCH).
 * Provenance and evidence are never read from the body (ADR-016).
 */
final class StoreProductDraftRequest extends FormRequest
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
            'expected_revision' => [$this->isMethod('patch') ? 'required' : 'prohibited', 'integer', 'min:1'],
            ...ProductDraftRules::rules(),
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        $rules = app(ProductDraftRules::class);

        return [function (Validator $validator) use ($rules): void {
            if ($validator->errors()->isEmpty()) {
                $rules->checkText($validator, $this->all());
            }
        }, fn (Validator $validator) => $rules->checkStatuses($validator, $this->all())];
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
