<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Exception;

use Myracloud\WebApi\Model\Violation;
use RuntimeException;

/**
 * Myra understood the request and rejected it (`error: true`); the reasons are in
 * {@see violations()}.
 */
final class RequestRejectedException extends RuntimeException implements MyraException
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(string $message, private readonly array $violations)
    {
        parent::__construct($message);
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
