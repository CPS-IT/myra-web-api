<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\CacheSetting;
use Myracloud\WebApi\Model\Page;

/**
 * The cache rules of a subdomain (vhost). See {@see Client} on implementing this interface;
 * `$subdomain` as in {@see Redirects}.
 */
interface CacheSettings
{
    /**
     * @return list<CacheSetting>
     *
     * @throws MyraException
     */
    public function list(string $subdomain): array;

    /**
     * @return Page<CacheSetting>
     *
     * @throws MyraException
     */
    public function page(string $subdomain, int $page): Page;

    /**
     * @throws InvalidArgumentException if the rule already has an id
     * @throws MyraException
     */
    public function create(CacheSetting $setting): CacheSetting;

    /**
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function update(CacheSetting $setting): CacheSetting;

    /**
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function delete(CacheSetting $setting): void;
}
