<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * `/{lang}/rapi/ipfilter/{subdomain}`: GET `/{page}` lists, PUT creates, POST updates,
 * DELETE deletes (body: id, modified).
 */
final readonly class IpFilters implements Contract\IpFilters
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function list(string $subdomain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($subdomain, $page));
    }

    public function page(string $subdomain, int $page): Page
    {
        return Page::fromJson($this->transport->send('GET', self::path($subdomain) . '/' . Page::number($page)), 'list', IpFilter::fromJson(...));
    }

    public function create(IpFilter $filter): IpFilter
    {
        Persisted::assertNew($filter->id(), 'IP filter');

        return IpFilter::fromJson($this->transport->send('PUT', self::path($filter->subdomain()), body: $filter->toJson())->first('targetObject'));
    }

    public function update(IpFilter $filter): IpFilter
    {
        Persisted::versionOf($filter->id(), $filter->modified(), 'IP filter');

        return IpFilter::fromJson($this->transport->send('POST', self::path($filter->subdomain()), body: $filter->toJson())->first('targetObject'));
    }

    public function delete(IpFilter $filter): void
    {
        [$id, $modified] = Persisted::versionOf($filter->id(), $filter->modified(), 'IP filter');
        $this->transport->send('DELETE', self::path($filter->subdomain()), body: ['id' => $id, 'modified' => $modified]);
    }

    private static function path(string $subdomain): string
    {
        return 'ipfilter/' . rawurlencode(DomainName::normalize($subdomain));
    }
}
