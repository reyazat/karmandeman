<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle of one n8n workflow run (ADR-032). */
enum AiRunStatus: string
{
    case Queued = 'queued';
    case Dispatched = 'dispatched';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';

    /** A callback can still change the batch. */
    public function isOpen(): bool
    {
        return $this === self::Queued || $this === self::Dispatched;
    }
}
