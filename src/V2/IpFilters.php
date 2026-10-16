<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;
use Myracloud\WebApi\Model\Violation;

/**
 * `/domain/{domainId}/ip-filters/{vHostName}`: GET lists (`?page=`), POST creates; with
 * `/{id}`: GET, PUT, DELETE; `/export` answers CSV, `/import` takes CSV.
 */
final readonly class IpFilters implements Contract\IpFilters
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    public function list(string $subdomain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($subdomain, $page));
    }

    public function page(string $subdomain, int $page): Page
    {
        $page = Page::number($page);

        return Page::fromJson($this->transport->send('GET', $this->path($subdomain), Domains::query($page)), 'data', IpFilter::fromJson(...));
    }

    /**
     * One entry by id (v2 only).
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function get(string $subdomain, int $id): IpFilter
    {
        return IpFilter::fromJson($this->transport->send('GET', $this->path($subdomain) . '/' . $id)->first('data'));
    }

    /**
     * All entries as CSV — columns "IP", "Expires on", "Comments"; type and enabled are not
     * part of it (v2 only).
     *
     * @param string|null $type only entries of this type (IpFilter::TYPE_…)
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function export(string $subdomain, ?string $type = null): string
    {
        return $this->transport->fetchText($this->path($subdomain) . '/export', $type === null ? [] : ['type' => $type]);
    }

    /**
     * Adds entries from CSV lines "IP,Expires on,Comments" (no header line), all of one type
     * and enabled; an existing entry with the same IP and type is changed instead (v2 only).
     *
     * @return list<Violation> one per line that was skipped — Myra still answers success then
     *                         (captured: HTTP 201, `error: false`, the spec says otherwise)
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function import(string $subdomain, string $type, string $csv): array
    {
        $answer = $this->transport->sendRaw('POST', $this->path($subdomain) . '/import', ['type' => $type], $csv, 'text/csv');

        return $answer->has('violationList') ? array_map(Violation::fromJson(...), $answer->objects('violationList')) : [];
    }

    public function create(IpFilter $filter): IpFilter
    {
        Persisted::assertNew($filter->id(), 'IP filter');

        return IpFilter::fromJson($this->transport->send('POST', $this->path($filter->subdomain()), body: $filter->toJson())->first('data'));
    }

    public function update(IpFilter $filter): IpFilter
    {
        [$id] = Persisted::versionOf($filter->id(), $filter->modified(), 'IP filter');

        return IpFilter::fromJson($this->transport->send('PUT', $this->path($filter->subdomain()) . '/' . $id, body: $filter->toJson())->first('data'));
    }

    public function delete(IpFilter $filter): void
    {
        [$id] = Persisted::versionOf($filter->id(), $filter->modified(), 'IP filter');
        $this->transport->send('DELETE', $this->path($filter->subdomain()) . '/' . $id);
    }

    private function path(string $subdomain): string
    {
        $subdomain = DomainName::normalize($subdomain);

        return 'domain/' . $this->domainIds->resolveHost($subdomain) . '/ip-filters/' . rawurlencode($subdomain);
    }
}
