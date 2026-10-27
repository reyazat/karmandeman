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
            'message.message_id' => ['sometimes', 'integer', 'min:1', $integer],
            'callback_query' => ['sometimes', 'array'],
            'callback_query.id' => ['required_with:callback_query', 'string', 'max:128'],
            'callback_query.from' => ['required_with:callback_query', 'array'],
            'callback_query.from.id' => ['required_with:callback_query', 'integer', 'min:1', 'max:4503599627370495', $integer],
            'callback_query.from.first_name' => ['sometimes', 'string', 'max:255'],
            'callback_query.from.last_name' => ['sometimes', 'string', 'max:255'],
            'callback_query.from.username' => ['sometimes', 'string', 'max:255'],
            'callback_query.message' => ['required_with:callback_query', 'array'],
            'callback_query.message.chat' => ['required_with:callback_query', 'array'],
            'callback_query.message.chat.id' => ['required_with:callback_query', 'integer', $integer],
            'callback_query.message.chat.type' => ['required_with:callback_query', 'string', 'in:private,group,supergroup,channel'],
            'callback_query.data' => ['required_with:callback_query', 'string', 'max:128',
                'regex:/^(onboard:(accept:[a-zA-Z0-9_-]{1,64}|unit:(toman|irr)|skip:(category|tone)|confirm:[0-9]+:[a-f0-9]{16}|edit)|delete:(confirm:[0-9]{1,12}:[a-f0-9]{16}|cancel))$/'],
        ];
    }
}
