<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * `/{lang}/rapi/dnsRecords/{domain}`: GET `/{page}` lists (`?loadbalancer=true&recordTypes=…`
 * for load balancing), PUT creates, POST updates, DELETE deletes (body: id, modified).
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
        $body = $this->transport->send('GET', self::path($domain) . '/' . Page::number($page));

        return Page::fromJson($body, 'list', DnsRecord::fromJson(...));
    }

    public function loadBalancing(string $domain): array
    {
        return Page::collect(fn(int $page): Page => Page::fromJson(
            // literally as captured: the HMAC signature covers the query
            $this->transport->send('GET', self::path($domain) . '/' . Page::number($page) . '?loadbalancer=true&recordTypes=A,AAAA,CNAME'),
            'list',
            DnsRecord::fromJson(...),
        ));
    }

    public function create(string $domain, DnsRecord $record): DnsRecord
    {
        Persisted::assertNew($record->id(), 'DNS record');

        return DnsRecord::fromJson($this->transport->send('PUT', self::path($domain), body: $record->toJson())->first('targetObject'));
    }

    public function update(string $domain, DnsRecord $record): DnsRecord
    {
        Persisted::versionOf($record->id(), $record->modified(), 'DNS record');

        return DnsRecord::fromJson($this->transport->send('POST', self::path($domain), body: $record->toJson())->first('targetObject'));
    }

    public function delete(string $domain, DnsRecord $record): void
    {
        [$id, $modified] = Persisted::versionOf($record->id(), $record->modified(), 'DNS record');
        $this->transport->send('DELETE', self::path($domain), body: ['id' => $id, 'modified' => $modified]);
    }

    private static function path(string $domain): string
    {
        return 'dnsRecords/' . rawurlencode(DomainName::normalize($domain));
    }
}
