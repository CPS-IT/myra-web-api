<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;
use Myracloud\WebApi\Model\Redirect;

/**
 * `/domain/{domainId}/redirects/{vHostName}`: GET lists (`?page=`), POST creates; with
 * `/{id}`: GET reads one, PUT updates, DELETE deletes. The domain is the zone the subdomain
 * belongs to. Writes unverified, see docs/api-surface.md.
 */
final readonly class Redirects implements Contract\Redirects
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
        $body = $this->transport->send('GET', $this->path($subdomain), Domains::query($page));

        return Page::fromJson($body, 'data', Redirect::fromJson(...));
    }

    /**
     * One redirect by id (v2 only).
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function get(string $subdomain, int $id): Redirect
    {
        return Redirect::fromJson($this->transport->send('GET', $this->path($subdomain) . '/' . $id)->first('data'));
    }

    public function create(Redirect $redirect): Redirect
    {
        Persisted::assertNew($redirect->id(), 'redirect');

        return Redirect::fromJson($this->transport->send('POST', $this->path($redirect->subdomain()), body: $redirect->toJson())->first('data'));
    }

    public function update(Redirect $redirect): Redirect
    {
        [$id] = Persisted::versionOf($redirect->id(), $redirect->modified(), 'redirect');

        return Redirect::fromJson($this->transport->send('PUT', $this->path($redirect->subdomain()) . '/' . $id, body: $redirect->toJson())->first('data'));
    }

    public function delete(Redirect $redirect): void
    {
        [$id] = Persisted::versionOf($redirect->id(), $redirect->modified(), 'redirect');
        $this->transport->send('DELETE', $this->path($redirect->subdomain()) . '/' . $id);
    }

    private function path(string $subdomain): string
    {
        $subdomain = DomainName::normalize($subdomain);

        return 'domain/' . $this->domainIds->resolveHost($subdomain) . '/redirects/' . rawurlencode($subdomain);
    }
}
