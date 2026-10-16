<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class DomainName
{
    /**
     * "Example.COM." and "example.com" are the same domain.
     *
     * @return non-empty-string
     *
     * @throws InvalidArgumentException if nothing is left
     */
    public static function normalize(string $name): string
    {
        $normalized = rtrim(strtolower(trim($name)), '.');
        if ($normalized === '') {
            throw new InvalidArgumentException('The domain name must not be empty.');
        }

        return $normalized;
    }
}
