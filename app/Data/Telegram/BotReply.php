<?php

declare(strict_types=1);

namespace App\Data\Telegram;

/** A bot answer with optional inline buttons. */
final readonly class BotReply
{
    /**
     * @param  array<int, array<int, array{text: string, callback_data: string}>>  $buttons
     */
    public function __construct(public string $text, public array $buttons = []) {}
}
