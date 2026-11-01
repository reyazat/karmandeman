<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

/** The seller confirms a CSV's mapping and, when the file names none, the price unit (ADR-040). */
final class ImportProductTableRequest extends FormRequest
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
            'price_unit' => ['sometimes', 'nullable', 'string', 'in:IRT,IRR'],
        ];
    }

    /** @return 'IRT'|'IRR'|null */
    public function unit(): ?string
    {
        $unit = $this->validated('price_unit');

        return $unit === 'IRT' || $unit === 'IRR' ? $unit : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
