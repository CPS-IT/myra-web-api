<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Model\Page;

/**
 * The domains (zones) of the account. See {@see Client} on implementing this interface.
 */
interface Domains
{
    /**
     * All domains: fetches every page before it returns.
     *
     * @return list<Domain>
     *
     * @throws MyraException
     */
    public function list(): array;

    /**
     * @param int $page 1-based
     *
     * @return Page<Domain>
     *
     * @throws MyraException
     */
    public function page(int $page): Page;
}
