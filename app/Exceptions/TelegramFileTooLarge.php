<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The bot file is above the asset size cap: tell the user, never retry (ADR-024). */
final class TelegramFileTooLarge extends RuntimeException {}
