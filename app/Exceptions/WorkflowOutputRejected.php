<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A signed n8n callback whose body breaks the versioned output schema
 * (ADR-032). Carries the failing paths only, never the values.
 */
final class WorkflowOutputRejected extends DomainException
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('WORKFLOW_OUTPUT_INVALID');
    }
}
