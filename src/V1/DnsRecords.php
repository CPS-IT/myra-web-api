<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Page;

/**
 * `GET /{lang}/rapi/dnsRecords/{domain}/{page}`
 */
final readonly class DnsRecords implements Contract\DnsRecords
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function list(string $domain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($domain, $page));
    }

    public function page(string $domain, int $page): Page
    {
        $body = $this->transport->send('GET', 'dnsRecords/' . rawurlencode(DomainName::normalize($domain)) . '/' . Page::number($page));

        return Page::fromJson($body, 'list', DnsRecord::fromJson(...));
    }
}
