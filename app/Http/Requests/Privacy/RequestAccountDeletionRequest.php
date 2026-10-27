<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

final class RequestAccountDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fresh signed initData is the re-authentication (FR-085); `confirm`
     * is the explicit final confirmation from the privacy page.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'initData' => ['required', 'string', 'max:8192'],
            'confirm' => ['required', 'accepted'],
        ];
    }
}
