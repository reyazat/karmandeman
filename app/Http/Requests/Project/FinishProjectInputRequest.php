<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

/**
 * finish-input: the batch version the user saw (ADR-018) and, when the new
 * photos go to a paid extraction run, the quote the user accepted (ADR-039).
 */
final class FinishProjectInputRequest extends FormRequest
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
            'version' => ['required', 'integer', 'min:1'],
            'quote_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }

    public function quoteId(): ?string
    {
        $id = $this->validated('quote_id');

        return is_string($id) ? strtolower($id) : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
