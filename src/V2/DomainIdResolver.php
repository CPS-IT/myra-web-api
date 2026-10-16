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

    /**
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function resolve(string $name): int
    {
        $name = DomainName::normalize($name);
        while (!isset($this->ids[$name]) && !$this->complete) {
            $page = $this->domains->page($this->nextPage++);
            foreach ($page->items() as $domain) {
                $this->ids[DomainName::normalize($domain->name())] = $domain->id();
            }
            $this->complete = !$page->hasNext() || $page->items() === [];
        }

        return $this->ids[$name] ?? throw new DomainNotFoundException(sprintf('The domain "%s" is not in the domain list of this account.', $name));
    }
}
