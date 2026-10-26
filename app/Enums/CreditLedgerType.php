<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditLedgerType: string
{
    case Grant = 'grant';
    case Purchase = 'purchase';
    case Reserve = 'reserve';
    case Capture = 'capture';
    case Release = 'release';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
}
