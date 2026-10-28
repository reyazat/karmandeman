<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** Gate A refused: these field paths are still unconfirmed (PRD E05). */
final class ProductRequiresConfirmation extends DomainException
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('PRODUCT_ATTRIBUTE_REQUIRES_CONFIRMATION');
    }
}
