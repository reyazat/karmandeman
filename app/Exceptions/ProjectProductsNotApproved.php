<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** Batch approval refused: these products still wait for Gate A (ADR-018). */
final class ProjectProductsNotApproved extends DomainException
{
    /**
     * @param  list<string>  $products  public product ids
     */
    public function __construct(public readonly array $products)
    {
        parent::__construct('PROJECT_PRODUCTS_NOT_APPROVED');
    }
}
