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

    /** States after PRODUCT_APPROVED: the latest snapshot is the batch (PRD §6, ADR-019). */
    public function hasApprovedSnapshot(): bool
    {
        return match ($this) {
            self::ProductApproved, self::Quoted, self::CreditReserved, self::Generating,
            self::OutputReviewRequired, self::Completed, self::RetryableFailure, self::Failed, self::Expired => true,
            default => false,
        };
    }

    /**
     * No credit is reserved and no AI job runs (FR-063, ADR-019). Extraction,
     * quotes and credit join this list when Steps 4 and 5 define their release.
     */
    public function isCancellable(): bool
    {
        return match ($this) {
            self::New, self::CollectingInput, self::NeedsClarification, self::ReviewRequired, self::ProductApproved => true,
            default => false,
        };
    }
}
