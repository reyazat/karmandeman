<?php

declare(strict_types=1);

namespace App\Data\Telegram;

final readonly class OnboardingReply
{
    /**
     * @param  array<int, array<int, array{text: string, callback_data: string}>>  $buttons
     */
    public function __construct(public string $text, public array $buttons = []) {}
}
