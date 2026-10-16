<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\InvalidArgumentException;

/**
 * The request body of a cache clear — the same `CacheClearVO` in v1 and v2.
 *
 * @internal
 */
final class CacheClearBody
{
    /**
     * @return array{fqdn: string, resource: string, recursive: bool}
     */
    public static function single(string $fqdn, string $resource, bool $recursive): array
    {
        return ['fqdn' => self::fqdn($fqdn), 'resource' => self::resource($resource), 'recursive' => $recursive];
    }

    /**
     * @param array<mixed> $resources
     *
     * @return array{fqdn: string, bulk: non-empty-list<string>, recursive: bool}
     *
     * @throws InvalidArgumentException
     */
    public static function bulk(string $fqdn, array $resources, bool $recursive): array
    {
        if ($resources === [] || !array_is_list($resources)) {
            throw new InvalidArgumentException('A bulk cache clear needs a non-empty list of resources.');
        }
        $bulk = [];
        foreach ($resources as $resource) {
            if (!is_string($resource)) {
                throw new InvalidArgumentException('Resources must be strings, ' . get_debug_type($resource) . ' given.');
            }
            $bulk[] = self::resource($resource);
        }

        return ['fqdn' => self::fqdn($fqdn), 'bulk' => $bulk, 'recursive' => $recursive];
    }

    private static function fqdn(string $fqdn): string
    {
        return DomainName::normalize($fqdn);
    }

    private static function resource(string $resource): string
    {
        if (!str_starts_with($resource, '/')) {
            throw new InvalidArgumentException(sprintf('A resource is a path pattern starting with "/", "%s" given.', $resource));
        }

        return $resource;
    }
}
