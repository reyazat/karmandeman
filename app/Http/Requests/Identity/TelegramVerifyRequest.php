<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Data\Telegram\TelegramUserData;
use App\Services\Telegram\TelegramInitDataValidator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

final class TelegramVerifyRequest extends FormRequest
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
            'initData' => ['required', 'string', 'max:8192'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /** Signed, fresh initData user, or null when the signature, age or user payload is invalid. */
    public function telegramUser(TelegramInitDataValidator $validator): ?TelegramUserData
    {
        $fields = $validator->validate($this->string('initData')->toString());
        $user = $fields !== null ? json_decode((string) ($fields['user'] ?? ''), true) : null;

        try {
            return is_array($user) ? TelegramUserData::fromArray($user) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
