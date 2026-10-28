<?php

declare(strict_types=1);

namespace App\Enums;

/** Product state, independent of its project (PRD §6). */
enum ProductStatus: string
{
    case DraftExtracted = 'draft_extracted';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case Archived = 'archived';
}
