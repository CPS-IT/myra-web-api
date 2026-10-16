<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Model\Page;

/**
 * The IP filter entries of a subdomain (vhost). See {@see Client} on implementing this
 * interface; `$subdomain` as in {@see Redirects}.
 */
interface IpFilters
{
    /**
     * @return list<IpFilter>
     *
     * @throws MyraException
     */
    public function list(string $subdomain): array;

    /**
     * @return Page<IpFilter>
     *
     * @throws MyraException
     */
    public function page(string $subdomain, int $page): Page;

    /**
     * @throws InvalidArgumentException if the entry already has an id
     * @throws MyraException
     */
    public function create(IpFilter $filter): IpFilter;

    /**
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function update(IpFilter $filter): IpFilter;

    /**
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function delete(IpFilter $filter): void;
}
