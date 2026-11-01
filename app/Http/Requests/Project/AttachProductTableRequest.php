<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

/** A seller's CSV of products (ADR-040). The bytes decide; the name and client type do not. */
final class AttachProductTableRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:'.(int) config('karmandeman.tables.max_kilobytes')],
        ];
    }

    public function bytes(): string
    {
        return (string) $this->file('file')?->get();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
