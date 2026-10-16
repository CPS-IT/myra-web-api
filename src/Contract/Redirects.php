<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Redirect;

/**
 * The redirects of a subdomain (vhost). See {@see Client} on implementing this interface.
 *
 * `$subdomain` is the fully qualified vhost, e.g. "www.example.com" or "example.com" for
 * the zone apex. Both versions list per vhost.
 *
 * Writes are *unverified* until captured against the real API (see docs/api-surface.md).
 */
interface Redirects
{
    /**
     * All redirects of the subdomain: fetches every page before it returns.
     *
     * @return list<Redirect>
     *
     * @throws MyraException
     */
    public function list(string $subdomain): array;

    /**
     * @param int $page 1-based
     *
     * @return Page<Redirect>
     *
     * @throws MyraException
     */
    public function page(string $subdomain, int $page): Page;

    /**
     * @param Redirect $redirect a new redirect (no id); its subdomain decides where it goes
     *
     * @return Redirect as created, with id and modified
     *
     * @throws InvalidArgumentException if the redirect already has an id
     * @throws MyraException
     */
    public function create(Redirect $redirect): Redirect;

    /**
     * @param Redirect $redirect read before (list, create, …) and changed with with…()
     *
     * @return Redirect as updated, with the new modified
     *
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function update(Redirect $redirect): Redirect;

    /**
     * @param Redirect $redirect read before; id and modified identify it
     *
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function delete(Redirect $redirect): void;
}
