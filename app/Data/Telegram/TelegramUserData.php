<?php

declare(strict_types=1);

namespace App\Data\Telegram;

use InvalidArgumentException;

final readonly class TelegramUserData
{
    public function __construct(
        public int $id,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $username = null,
        public ?string $languageCode = null,
    ) {}

    public static function fromArray(array $data): self
    {
        if (! is_int($data['id'] ?? null) || $data['id'] <= 0 || $data['id'] > 4503599627370495) {
            throw new InvalidArgumentException('Invalid Telegram user ID.');
        }

        foreach (['first_name', 'last_name', 'username', 'language_code'] as $field) {
            if (isset($data[$field]) && (! is_string($data[$field]) || mb_strlen($data[$field]) > 255)) {
                throw new InvalidArgumentException('Invalid Telegram user field.');
            }
        }

        return new self(
            id: $data['id'],
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            username: $data['username'] ?? null,
            languageCode: $data['language_code'] ?? null,
        );
    }
}
