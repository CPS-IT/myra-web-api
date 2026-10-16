<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\Page;

/**
 * The DNS records of a domain. See {@see Client} on implementing this interface.
 *
 * `$domain` is the domain (zone) name as in the account, e.g. "example.com" — not a
 * subdomain. Case and a trailing dot do not matter.
 */
interface DnsRecords
{
    /**
     * All records of the domain: fetches every page before it returns.
     *
     * @return list<DnsRecord>
     *
     * @throws MyraException
     */
    public function list(string $domain): array;

    /**
     * @param int $page 1-based
     *
     * @return Page<DnsRecord>
     *
     * @throws MyraException
     */
    public function page(string $domain, int $page): Page;
}
