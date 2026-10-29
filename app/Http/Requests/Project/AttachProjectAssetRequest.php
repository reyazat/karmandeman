<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

/** One image per request; the Action re-checks the bytes themselves (ADR-022). */
final class AttachProjectAssetRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:'.(int) config('karmandeman.assets.max_kilobytes'), 'mimetypes:image/jpeg,image/png,image/webp'],
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
