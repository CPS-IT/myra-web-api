<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\DomainName;

/**
 * Domain name → id: API v2 addresses domains by id only and rejects the name.
 *
 * Reads `/domains` page by page only as far as needed and remembers every name it has
 * seen, for the lifetime of the client. Once all pages are read, an unknown name fails
 * without another request.
 *
 * @internal
 */
final class DomainIdResolver
{
    /** @var array<string, int> */
    private array $ids = [];
    private int $nextPage = 1;
    private bool $complete = false;

    public function __construct(private readonly Domains $domains) {}

    private function readNextPage(): void
    {
        $page = $this->domains->page($this->nextPage++);
        foreach ($page->items() as $domain) {
            $this->ids[DomainName::normalize($domain->name())] = $domain->id();
        }
        $this->complete = !$page->hasNext() || $page->items() === [];
    }

    /**
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function resolve(string $name): int
    {
        $name = DomainName::normalize($name);
        while (!isset($this->ids[$name]) && !$this->complete) {
            $this->readNextPage();
        }

        return $this->ids[$name] ?? throw new DomainNotFoundException(sprintf('The domain "%s" is not in the domain list of this account.', $name));
    }

    /**
     * The id of the domain (zone) a host belongs to: the longest domain name the host equals
     * or ends with — "www.shop.example.com" belongs to "shop.example.com" if the account has
     * both. Reads the whole domain list once.
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function resolveHost(string $host): int
    {
        $host = DomainName::normalize($host);
        while (!$this->complete) {
            $this->readNextPage();
        }
        $best = null;
        foreach ($this->ids as $name => $id) {
            if (($host === $name || str_ends_with($host, '.' . $name)) && ($best === null || strlen($name) > strlen($best))) {
                $best = $name;
            }
        }

        return $best !== null ? $this->ids[$best] : throw new DomainNotFoundException(sprintf('The host "%s" belongs to no domain of this account.', $host));
    }
}
