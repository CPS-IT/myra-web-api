<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * `GET /domains`, `?page={page}` from page 2 on; `GET /domains/{id}`; POST `/domains`
 * creates, PUT / DELETE `/domains/{id}`.
 */
final readonly class Domains implements Contract\Domains
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function list(): array
    {
        return Page::collect($this->page(...));
    }

    /**
     * One domain with everything Myra has on it (permissions, verified, environment, … in
     * raw()); the id from list() or {@see Client::domainId()}.
     *
     * @throws \Myracloud\WebApi\Exception\MyraException
     */
    public function get(int $id): Domain
    {
        return Domain::fromJson($this->transport->send('GET', 'domains/' . $id)->first('data'));
    }

    public function page(int $page): Page
    {
        $body = $this->transport->send('GET', 'domains', self::query(Page::number($page)));

        return Page::fromJson($body, 'data', Domain::fromJson(...));
    }

    public function create(string $name, ?bool $autoUpdate = null, ?bool $autoDns = null): Domain
    {
        $body = ['name' => $name] + ($autoUpdate === null ? [] : ['autoUpdate' => $autoUpdate]) + ($autoDns === null ? [] : ['autoDns' => $autoDns]);

        return Domain::fromJson($this->transport->send('POST', 'domains', body: $body)->first('data'));
    }

    public function setAutoUpdate(Domain $domain, bool $autoUpdate): Domain
    {
        return $this->update($domain, ['autoUpdate' => $autoUpdate]);
    }

    /**
     * Switches the domain between "live" and "test" (a configuration to check with Myra's
     * support before it goes live; v2 only).
     *
     * @param string $environment "live" or "test"
     *
     * @throws \Myracloud\WebApi\Exception\InvalidArgumentException if modified is missing
     * @throws \Myracloud\WebApi\Exception\MyraException
     */
    public function setEnvironment(Domain $domain, string $environment): Domain
    {
        return $this->update($domain, ['environment' => $environment]);
    }

    public function delete(Domain $domain): void
    {
        $this->transport->send('DELETE', 'domains/' . $domain->id());
    }

    /**
     * The spec's update body: id, modified and the change — Myra ignores everything else.
     *
     * @param array<string, mixed> $changes
     */
    private function update(Domain $domain, array $changes): Domain
    {
        [$id, $modified] = Persisted::versionOf($domain->id(), $domain->modified(), 'domain');

        return Domain::fromJson($this->transport->send('PUT', 'domains/' . $id, body: ['id' => $id, 'modified' => $modified] + $changes)->first('data'));
    }

    /**
     * Page 1 without query: the first request is then exactly the captured one (HMAC over a
     * query string is not verified against Myra yet, see docs/2.0-design.md, Open).
     *
     * @internal
     *
     * @return array<string, int>
     */
    public static function query(int $page): array
    {
        return $page === 1 ? [] : ['page' => $page];
    }
}
