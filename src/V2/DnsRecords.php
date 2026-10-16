<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;
use Myracloud\WebApi\Model\UpstreamOptions;

/**
 * `/domain/{domainId}/dns-records` (`?page=` from page 2 on): GET lists, POST creates; with
 * `/{id}`: GET, PUT, DELETE; `/{id}/load-balancing`: PUT. `/domain/{domainId}/load-balancing`
 * lists the load-balanced records, `/domain/{domainId}/vhost/{vhostId}/dns-records` a vhost's.
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
        $body = $this->transport->send('GET', $this->path($domain), Domains::query($page));

        return Page::fromJson($body, 'data', DnsRecord::fromJson(...));
    }

    /**
     * One record by id (v2 only).
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function get(string $domain, int $id): DnsRecord
    {
        return DnsRecord::fromJson($this->transport->send('GET', $this->path($domain) . '/' . $id)->first('data'));
    }

    /**
     * The records of one vhost, by its v2 id (`vhostId` of a record; v2 only).
     *
     * @return list<DnsRecord>
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function forVhost(string $domain, int $vhostId): array
    {
        $path = 'domain/' . $this->domainIds->resolve($domain) . '/vhost/' . $vhostId . '/dns-records';

        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', $path, Domains::query($page)), 'data', DnsRecord::fromJson(...)));
    }

    public function loadBalancing(string $domain): array
    {
        $path = 'domain/' . $this->domainIds->resolve($domain) . '/load-balancing';

        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', $path, Domains::query($page)), 'data', DnsRecord::fromJson(...)));
    }

    /**
     * Changes only the load balancing settings of a record (v2 only; in v1 they are part of
     * update()): the UpstreamOptions fields, identified by the DNS record's id and modified.
     *
     * @return UpstreamOptions as changed
     *
     * @throws InvalidArgumentException if the record lacks id and modified or has no options
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function updateLoadBalancing(string $domain, DnsRecord $record): UpstreamOptions
    {
        [$id, $modified] = Persisted::versionOf($record->id(), $record->modified(), 'DNS record');
        $options = $record->upstreamOptions() ?? throw new InvalidArgumentException('The DNS record has no load balancing settings (upstreamOptions).');
        $body = ['id' => $id, 'modified' => $modified] + array_diff_key($options->toJson(), ['id' => true, 'modified' => true]);

        return UpstreamOptions::fromJson($this->transport->send('PUT', $this->path($domain) . '/' . $id . '/load-balancing', body: $body)->first('data'));
    }

    public function create(string $domain, DnsRecord $record): DnsRecord
    {
        Persisted::assertNew($record->id(), 'DNS record');

        return DnsRecord::fromJson($this->transport->send('POST', $this->path($domain), body: $record->toJson())->first('data'));
    }

    public function update(string $domain, DnsRecord $record): DnsRecord
    {
        [$id] = Persisted::versionOf($record->id(), $record->modified(), 'DNS record');

        return DnsRecord::fromJson($this->transport->send('PUT', $this->path($domain) . '/' . $id, body: $record->toJson())->first('data'));
    }

    public function delete(string $domain, DnsRecord $record): void
    {
        [$id] = Persisted::versionOf($record->id(), $record->modified(), 'DNS record');
        $this->transport->send('DELETE', $this->path($domain) . '/' . $id);
    }

    private function path(string $domain): string
    {
        return 'domain/' . $this->domainIds->resolve($domain) . '/dns-records';
    }
}
