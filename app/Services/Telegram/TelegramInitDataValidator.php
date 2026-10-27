<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\TelegramUserData;
use InvalidArgumentException;

/**
 * Validates Telegram Mini App initData per the official algorithm.
 * initDataUnsafe is never trusted; only the signed fields are read.
 */
final readonly class TelegramInitDataValidator
{
    private const MAX_AUTH_AGE_SECONDS = 300;

    /** Signed, fresh initData user, or null when the signature, age or user payload is invalid. */
    public function verifiedUser(string $initData): ?TelegramUserData
    {
        $fields = $this->validate($initData);
        $user = $fields !== null ? json_decode((string) ($fields['user'] ?? ''), true) : null;

        try {
            return is_array($user) ? TelegramUserData::fromArray($user) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, string>|null parsed initData fields on success
     */
    public function validate(string $initData): ?array
    {
        $botToken = config('services.telegram.bot_token');

        if (empty($initData) || empty($botToken)) {
            return null;
        }

        $fields = [];
        foreach (explode('&', $initData) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            if (count($parts) !== 2 || ! preg_match('/^[a-z_]+$/D', $key) || array_key_exists($key, $fields)) {
                return null;
            }
            $fields[$key] = urldecode($parts[1]);
        }

        $hash = $fields['hash'] ?? null;
        unset($fields['hash']);

        if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash) || $fields === []) {
            return null;
        }

        ksort($fields);

        $checkString = implode("\n", array_map(
            fn (string $key, mixed $value): string => $key.'='.$value,
            array_keys($fields),
            $fields,
        ));

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', $checkString, $secretKey);

        if (! hash_equals($expected, $hash)) {
            return null;
        }

        $date = $fields['auth_date'] ?? '';
        if (! ctype_digit($date)) {
            return null;
        }
        $authDate = (int) $date;

        if ($authDate <= 0 || $authDate > time() + 30 || time() - $authDate > self::MAX_AUTH_AGE_SECONDS) {
            return null;
        }

        return $fields;
    }
}
