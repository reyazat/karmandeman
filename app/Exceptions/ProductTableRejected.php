<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A CSV import refused whole (ADR-040); the lines name the rows at fault. */
final class ProductTableRejected extends DomainException
{
    /**
     * @param  list<int>  $lines
     */
    public function __construct(string $code, public readonly array $lines)
    {
        parent::__construct($code);
    }
}
