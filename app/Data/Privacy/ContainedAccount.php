<?php

declare(strict_types=1);

namespace App\Data\Privacy;

use App\Models\AccountDeletion;

/** A known account whose workspace no longer accepts work; deletion is null when no receipt exists. */
final readonly class ContainedAccount
{
    public function __construct(public ?AccountDeletion $deletion) {}
}
