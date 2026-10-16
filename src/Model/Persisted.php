<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\InvalidArgumentException;

/**
 * Guards for writes: create takes a new object, update and delete one read before.
 *
 * @internal
 */
final class Persisted
{
    /**
     * @throws InvalidArgumentException if the object already has an id
     */
    public static function assertNew(?int $id, string $what): void
    {
        if ($id !== null) {
            throw new InvalidArgumentException(sprintf('Cannot create a %s that already has an id (%d); update it instead.', $what, $id));
        }
    }

    /**
     * @return array{int, string} id and modified
     *
     * @throws InvalidArgumentException if id or modified is missing
     */
    public static function versionOf(?int $id, ?string $modified, string $what): array
    {
        if ($id === null || $modified === null) {
            throw new InvalidArgumentException(sprintf('A %s needs id and modified to be changed: use the object Myra returned (list, get or create).', $what));
        }

        return [$id, $modified];
    }
}
