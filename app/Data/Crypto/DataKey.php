<?php

declare(strict_types=1);

namespace App\Data\Crypto;

/** A fresh data key: raw bytes for in-memory use only, plus its Transit-wrapped form for storage. */
final readonly class DataKey
{
    public function __construct(
        #[\SensitiveParameter] public string $plaintext,
        public string $wrapped,
    ) {}
}
