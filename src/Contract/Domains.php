<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Model\Page;

/**
 * The domains (zones) of the account. See {@see Client} on implementing this interface.
 *
 * Reads captured. The writes are *unverified* (from the manual / spec — creating or deleting
 * a domain is never probed: a delete removes all its configuration and can take a site offline).
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

    /**
     * Adds a domain. A name overlapping another organisation's domain is created pending
     * verification (v2 spec: `verified: false` and the warning DOMAIN_PENDING_VERIFICATION).
     *
     * @param bool|null $autoUpdate deploy changes at once; null = Myra's default
     * @param bool|null $autoDns look for subdomains when creating it; null = Myra's default
     *
     * @throws MyraException
     */
    public function create(string $name, ?bool $autoUpdate = null, ?bool $autoDns = null): Domain;

    /**
     * Turns the deployment of changes on or off (off: changes are saved, not deployed — for
     * many changes at once) on a domain read before.
     *
     * @throws InvalidArgumentException if modified is missing
     * @throws MyraException
     */
    public function setAutoUpdate(Domain $domain, bool $autoUpdate): Domain;

    /**
     * Deletes a domain with ALL its configuration — this can take the site offline. A soft
     * delete: Myra's support can restore it.
     *
     * @throws MyraException
     */
    public function delete(Domain $domain): void;
}
