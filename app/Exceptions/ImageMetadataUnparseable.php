<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A stored photo whose container could not be cleaned of metadata, so it is not sent (ADR-033). */
final class ImageMetadataUnparseable extends DomainException
{
    public function __construct()
    {
        parent::__construct('ASSET_METADATA_UNPARSEABLE');
    }
}
