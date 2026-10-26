<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectState: string
{
    case New = 'new';
    case CollectingInput = 'collecting_input';
    case Extracting = 'extracting';
    case NeedsClarification = 'needs_clarification';
    case ReviewRequired = 'review_required';
    case ProductApproved = 'product_approved';
    case Quoted = 'quoted';
    case CreditReserved = 'credit_reserved';
    case Generating = 'generating';
    case OutputReviewRequired = 'output_review_required';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case RetryableFailure = 'retryable_failure';
    case Failed = 'failed';
    case Expired = 'expired';
}
