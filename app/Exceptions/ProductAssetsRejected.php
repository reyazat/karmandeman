<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * These image_asset_ids paths name no asset of the product's own batch (ADR-023).
 * Unknown and foreign ids are refused alike, so existence never leaks.
 */
final class ProductAssetsRejected extends DomainException
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('PRODUCT_ASSET_INVALID');
    }
}
