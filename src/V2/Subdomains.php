<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Subdomain;

/**
 * The subdomains (vhosts) Myra serves (v2 only): `GET /subdomains` for the account,
 * `GET /domain/{domainId}/subdomains` for one domain.
 *
 * Captured: the account list ignores paging (it answers every entry whatever page is asked
 * for), so there is no page() for it; per domain, paging works.
 */
final readonly class Subdomains
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    /**
     * Every subdomain of the account, or of one domain.
     *
     * @return list<Subdomain>
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function list(?string $domain = null): array
    {
        if ($domain === null) {
            return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', 'subdomains', Domains::query($page)), 'data', Subdomain::fromJson(...)));
        }

        return Page::collect(fn(int $page): Page => $this->page($domain, $page));
    }

    /**
     * @param int $page 1-based
     *
     * @return Page<Subdomain>
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function page(string $domain, int $page): Page
    {
        $body = $this->transport->send('GET', 'domain/' . $this->domainIds->resolve($domain) . '/subdomains', Domains::query(Page::number($page)));

        return Page::fromJson($body, 'data', Subdomain::fromJson(...));
    }

    /**
     * The subdomains of the account whose name contains $term — or is exactly $term.
     *
     * @return list<Subdomain>
     *
     * @throws MyraException
     */
    public function search(string $term, bool $exact = false): array
    {
        $query = ['search' => $term] + ($exact ? ['filterType' => 'exact'] : []);

        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', 'subdomains', $query + Domains::query($page)), 'data', Subdomain::fromJson(...)));
    }
}
