<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The start request reached n8n but its answer was lost (ADR-037). n8n may
 * already run the workflow, so a retry could pay for the model twice.
 */
final class WorkflowStartUncertain extends RuntimeException {}
