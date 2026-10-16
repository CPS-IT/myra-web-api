<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\MaintenanceTemplate;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * Stored maintenance pages of a domain (v2 only): `/domain/{domainId}/maintenance-templates`
 * — GET lists, POST creates; with `/{id}`: PUT, DELETE.
 */
final readonly class MaintenanceTemplates
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    /**
     * @return list<MaintenanceTemplate>
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function list(string $domain): array
    {
        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', $this->path($domain), Domains::query($page)), 'data', MaintenanceTemplate::fromJson(...)));
    }

    /**
     * @throws InvalidArgumentException if it already has an id
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function create(string $domain, MaintenanceTemplate $template): MaintenanceTemplate
    {
        Persisted::assertNew($template->id(), 'maintenance template');

        return MaintenanceTemplate::fromJson($this->transport->send('POST', $this->path($domain), body: $template->toJson())->first('data'));
    }

    /**
     * @throws InvalidArgumentException if id or modified is missing
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function update(string $domain, MaintenanceTemplate $template): MaintenanceTemplate
    {
        [$id] = Persisted::versionOf($template->id(), $template->modified(), 'maintenance template');

        return MaintenanceTemplate::fromJson($this->transport->send('PUT', $this->path($domain) . '/' . $id, body: $template->toJson())->first('data'));
    }

    /**
     * @throws InvalidArgumentException if id or modified is missing
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function delete(string $domain, MaintenanceTemplate $template): void
    {
        [$id] = Persisted::versionOf($template->id(), $template->modified(), 'maintenance template');
        $this->transport->send('DELETE', $this->path($domain) . '/' . $id);
    }

    private function path(string $domain): string
    {
        return 'domain/' . $this->domainIds->resolve($domain) . '/maintenance-templates';
    }
}
