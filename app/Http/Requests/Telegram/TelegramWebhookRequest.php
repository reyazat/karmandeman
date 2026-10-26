<?php

declare(strict_types=1);

namespace App\Http\Requests\Telegram;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class TelegramWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $integer = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail('The field must be a JSON integer.');
            }
        };

        return [
            'update_id' => ['required', 'integer', 'min:0', $integer],
            'message' => ['sometimes', 'array'],
            'message.from' => ['required_with:message', 'array'],
            'message.from.id' => ['required_with:message', 'integer', 'min:1', 'max:4503599627370495', $integer],
            'message.from.first_name' => ['sometimes', 'string', 'max:255'],
            'message.from.last_name' => ['sometimes', 'string', 'max:255'],
            'message.from.username' => ['sometimes', 'string', 'max:255'],
            'message.from.language_code' => ['sometimes', 'string', 'max:255'],
            'message.chat' => ['required_with:message', 'array'],
            'message.chat.id' => ['required_with:message', 'integer', $integer],
            'message.chat.type' => ['required_with:message', 'string', 'in:private,group,supergroup,channel'],
            'message.text' => ['sometimes', 'string', 'max:4096'],
        ];
    }
}
