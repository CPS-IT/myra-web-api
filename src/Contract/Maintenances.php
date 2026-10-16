<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\Maintenance;
use Myracloud\WebApi\Model\Page;

/**
 * The maintenance pages of a subdomain (vhost). See {@see Client} on implementing this
 * interface; `$subdomain` as in {@see Redirects}.
 *
 * Careful: a maintenance whose period has begun takes the subdomain offline immediately.
 */
interface Maintenances
{
    /**
     * @return list<Maintenance>
     *
     * @throws MyraException
     */
    public function list(string $subdomain): array;

    /**
     * @return Page<Maintenance>
     *
     * @throws MyraException
     */
    public function page(string $subdomain, int $page): Page;

    /**
     * @throws InvalidArgumentException if it already has an id
     * @throws MyraException
     */
    public function create(Maintenance $maintenance): Maintenance;

    /**
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function update(Maintenance $maintenance): Maintenance;

    /**
     * Ends an active maintenance too.
     *
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function delete(Maintenance $maintenance): void;
}
