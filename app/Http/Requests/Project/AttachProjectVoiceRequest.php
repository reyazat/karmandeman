<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

/** Upload metadata is only a hint; OggOpusInspector checks the bytes. */
final class AttachProjectVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'file' => ['required', 'file', 'max:'.(int) config('karmandeman.voice.max_kilobytes')],
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
