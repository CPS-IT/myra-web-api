<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Exception;

use RuntimeException;
use Throwable;

/**
 * No usable answer: network failure, server error (5xx), or a response this package cannot
 * read (not JSON, unexpected status, missing or mistyped field).
 */
final class TransportException extends RuntimeException implements MyraException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
