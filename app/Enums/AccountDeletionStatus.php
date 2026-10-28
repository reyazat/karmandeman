<?php

declare(strict_types=1);

namespace App\Enums;

/** Erasure lifecycle (FR-086…088, ADR-014); completed only after backup expiry is evidenced. */
enum AccountDeletionStatus: string
{
    case Requested = 'requested';
    case AwaitingBackupExpiry = 'awaiting_backup_expiry';
    case Completed = 'completed';
}
