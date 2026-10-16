<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\Page;

/**
 * `GET /domain/{domainId}/dns-records`, `?page={page}` from page 2 on.
 */
final readonly class DnsRecords implements Contract\DnsRecords
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    public function list(string $domain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($domain, $page));
    }

    public function page(string $domain, int $page): Page
    {
        $page = Page::number($page);
        $body = $this->transport->send('GET', 'domain/' . $this->domainIds->resolve($domain) . '/dns-records', Domains::query($page));

        return Page::fromJson($body, 'data', DnsRecord::fromJson(...));
    }
}
