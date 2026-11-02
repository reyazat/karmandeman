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

    /**
     * A batch takes new input until it is approved (FR-022 as read by ADR-041),
     * from the API and the bot alike, extraction included: the next /done reads
     * the new photos.
     */
    public const OPEN_FOR_INPUT = [self::CollectingInput, self::NeedsClarification, self::Extracting, self::ReviewRequired];

    public function isOpenForInput(): bool
    {
        return in_array($this, self::OPEN_FOR_INPUT, true);
    }

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
     * No credit is reserved (FR-063, ADR-019). Cancel during extraction closes
     * the open run, so its late callback changes nothing (ADR-032). Quotes and
     * credit join this list when Step 5 defines their release.
     */
    public function isCancellable(): bool
    {
        return match ($this) {
            self::New, self::CollectingInput, self::Extracting, self::NeedsClarification, self::ReviewRequired, self::ProductApproved => true,
            default => false,
        };
    }
}
