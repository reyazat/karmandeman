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
            // Photo intake (ADR-024): only what the server needs to fetch the file itself.
            'message.photo' => ['sometimes', 'array', 'max:20'],
            'message.photo.*' => ['array'],
            'message.photo.*.file_id' => ['required', 'string', 'max:255'],
            'message.photo.*.width' => ['sometimes', 'integer', 'min:0'],
            'message.photo.*.height' => ['sometimes', 'integer', 'min:0'],
            'message.photo.*.file_size' => ['sometimes', 'integer', 'min:0'],
            'message.document' => ['sometimes', 'array'],
            'message.document.file_id' => ['required_with:message.document', 'string', 'max:255'],
            'message.document.mime_type' => ['sometimes', 'string', 'max:100'],
            'message.document.file_size' => ['sometimes', 'integer', 'min:0'],
            'callback_query' => ['sometimes', 'array'],
            'callback_query.id' => ['required_with:callback_query', 'string', 'max:128'],
            'callback_query.from' => ['required_with:callback_query', 'array'],
            'callback_query.from.id' => ['required_with:callback_query', 'integer', 'min:1', 'max:4503599627370495', $integer],
            'callback_query.from.first_name' => ['sometimes', 'string', 'max:255'],
            'callback_query.from.last_name' => ['sometimes', 'string', 'max:255'],
            'callback_query.from.username' => ['sometimes', 'string', 'max:255'],
            // Unknown, inline or stale callbacks are acknowledged and ignored by the service,
            // never rejected here: a non-2xx answer makes Telegram retry them.
            'callback_query.message' => ['sometimes', 'array'],
            'callback_query.message.chat' => ['required_with:callback_query.message', 'array'],
            'callback_query.message.chat.id' => ['required_with:callback_query.message', 'integer', $integer],
            'callback_query.message.chat.type' => ['required_with:callback_query.message', 'string', 'in:private,group,supergroup,channel'],
            'callback_query.data' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
