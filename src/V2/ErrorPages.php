<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\ErrorPage;
use Myracloud\WebApi\Model\ErrorPageSelection;
use Myracloud\WebApi\Model\Page;

/**
 * `/domain/{domainId}/errorpages`: GET lists, POST sets, DELETE deletes (body: selection);
 * `/{id}` reads one; `/domain/{domainId}/errorpages-grouped` groups by subdomain.
 */
final readonly class ErrorPages implements Contract\ErrorPages
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
        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', $this->path($domain), Domains::query($page)), 'data', static fn(JsonObject $json): ErrorPage => ErrorPage::fromJson($json)));
    }

    /**
     * The error pages by subdomain (v2 only).
     *
     * @return array<string, list<ErrorPage>>
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function grouped(string $domain): array
    {
        $data = $this->transport->send('GET', 'domain/' . $this->domainIds->resolve($domain) . '/errorpages-grouped')->object('data');
        $grouped = [];
        foreach (array_keys($data->toArray()) as $subdomain) {
            $grouped[(string) $subdomain] = array_map(static fn(JsonObject $json): ErrorPage => ErrorPage::fromJson($json), $data->objects((string) $subdomain));
        }

        return $grouped;
    }

    public function get(string $domain, int $id): ErrorPage
    {
        return ErrorPage::fromJson($this->transport->send('GET', $this->path($domain) . '/' . $id)->first('data'));
    }

    public function set(string $domain, string $html, array $codesBySubdomain): void
    {
        $this->transport->send('POST', $this->path($domain), body: ['pageContent' => $html, 'selection' => ErrorPageSelection::toJson($codesBySubdomain)]);
    }

    public function delete(string $domain, array $codesBySubdomain): void
    {
        $this->transport->send('DELETE', $this->path($domain), body: ['selection' => ErrorPageSelection::toJson($codesBySubdomain)]);
    }

    private function path(string $domain): string
    {
        return 'domain/' . $this->domainIds->resolve($domain) . '/errorpages';
    }
}
