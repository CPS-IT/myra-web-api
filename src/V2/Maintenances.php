<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Maintenance;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * `/domain/{domainId}/{vHostName}/maintenances` (vhost before area): GET lists, POST creates;
 * with `/{id}`: PUT, DELETE. Domain-wide: `/domain/{domainId}/maintenances[/{id}]`. `active`
 * is settable.
 */
final readonly class Maintenances implements Contract\Maintenances
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

        return Page::fromJson($this->transport->send('GET', $this->path($subdomain), Domains::query($page)), 'data', Maintenance::fromJson(...));
    }

    /**
     * The maintenance pages of all subdomains of a domain (v2 only).
     *
     * @param bool|null $presentAndFuture true: end in the future; false: end in the past; null: all
     *
     * @return list<Maintenance>
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function forDomain(string $domain, ?bool $presentAndFuture = null): array
    {
        $path = 'domain/' . $this->domainIds->resolve($domain) . '/maintenances';
        $filter = $presentAndFuture === null ? [] : ['presentAndFuture' => $presentAndFuture ? 'true' : 'false'];

        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', $path, $filter + Domains::query($page)), 'data', Maintenance::fromJson(...)));
    }

    /**
     * One maintenance page by id (v2 only).
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function get(string $domain, int $id): Maintenance
    {
        return Maintenance::fromJson($this->transport->send('GET', 'domain/' . $this->domainIds->resolve($domain) . '/maintenances/' . $id)->first('data'));
    }

    public function create(Maintenance $maintenance): Maintenance
    {
        Persisted::assertNew($maintenance->id(), 'maintenance');

        return Maintenance::fromJson($this->transport->send('POST', $this->path($maintenance->subdomain()), body: $maintenance->toJson(true))->first('data'));
    }

    public function update(Maintenance $maintenance): Maintenance
    {
        [$id] = Persisted::versionOf($maintenance->id(), $maintenance->modified(), 'maintenance');

        return Maintenance::fromJson($this->transport->send('PUT', $this->path($maintenance->subdomain()) . '/' . $id, body: $maintenance->toJson(true))->first('data'));
    }

    public function delete(Maintenance $maintenance): void
    {
        [$id] = Persisted::versionOf($maintenance->id(), $maintenance->modified(), 'maintenance');
        $this->transport->send('DELETE', $this->path($maintenance->subdomain()) . '/' . $id);
    }

    private function path(string $subdomain): string
    {
        $subdomain = DomainName::normalize($subdomain);

        return 'domain/' . $this->domainIds->resolveHost($subdomain) . '/' . rawurlencode($subdomain) . '/maintenances';
    }
}
