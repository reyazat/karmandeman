<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Stored ciphertext is malformed or fails its tag/AAD check: tampered, not a key-store outage. */
final class UndecryptableValue extends RuntimeException {}
