<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\CacheClear;

/**
 * Enqueues cache clears on all Myra nodes. See {@see Client} on implementing this interface.
 *
 * `$domain` is the domain (zone) name, `$fqdn` the host within it, e.g. "www.example.com".
 *
 * A resource is a **pattern**, matched against the path: "/*" is the whole host,
 * "/folder/*" a folder, "/*.css" all stylesheets. `$recursive` only extends the pattern to
 * subdirectories — "/" with `$recursive = true` does NOT clear everything.
 *
 * Myra confirms each clear with one CacheClear object per host.
 */
interface CacheClearing
{
    /**
     * @return list<CacheClear>
     *
     * @throws MyraException
     */
    public function clear(string $domain, string $fqdn, string $resource, bool $recursive = false): array;

    /**
     * Several patterns of one host in one request.
     *
     * @param non-empty-list<string> $resources
     *
     * @return list<CacheClear>
     *
     * @throws MyraException
     */
    public function clearBulk(string $domain, string $fqdn, array $resources, bool $recursive = false): array;
}
