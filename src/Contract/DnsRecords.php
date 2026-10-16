<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\InvalidArgumentException;
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

    /**
     * The records with load balancing settings (A, AAAA, CNAME).
     *
     * @return list<DnsRecord>
     *
     * @throws MyraException
     */
    public function loadBalancing(string $domain): array;

    /**
     * @param DnsRecord $record a new record (no id)
     *
     * @throws InvalidArgumentException if the record already has an id
     * @throws MyraException
     */
    public function create(string $domain, DnsRecord $record): DnsRecord;

    /**
     * @param DnsRecord $record read before and changed with with…()
     *
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function update(string $domain, DnsRecord $record): DnsRecord;

    /**
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function delete(string $domain, DnsRecord $record): void;
}
