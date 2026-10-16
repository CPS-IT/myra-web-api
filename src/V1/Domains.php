<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use DateTimeInterface;
use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * `GET /{lang}/rapi/domains/{page}`; `/{lang}/rapi/domains`: PUT creates, POST updates, DELETE
 * with `{id}` (manual 5.2).
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

    public function page(int $page): Page
    {
        $body = $this->transport->send('GET', 'domains/' . Page::number($page));

        return Page::fromJson($body, 'list', Domain::fromJson(...));
    }

    public function create(string $name, ?bool $autoUpdate = null, ?bool $autoDns = null): Domain
    {
        $body = ['name' => $name] + ($autoUpdate === null ? [] : ['autoUpdate' => $autoUpdate]) + ($autoDns === null ? [] : ['autoDns' => $autoDns]);

        return Domain::fromJson($this->transport->send('PUT', 'domains', body: $body)->first('targetObject'));
    }

    public function setAutoUpdate(Domain $domain, bool $autoUpdate): Domain
    {
        return $this->update($domain, ['autoUpdate' => $autoUpdate]);
    }

    /**
     * Pauses Myra for the domain (v1 only) — until a moment, or until resume().
     *
     * @throws InvalidArgumentException if modified is missing
     * @throws MyraException
     */
    public function pause(Domain $domain, ?DateTimeInterface $until = null): Domain
    {
        return $this->update($domain, ['paused' => true] + ($until === null ? [] : ['pausedUntil' => $until->format(DATE_ATOM)]));
    }

    /**
     * @throws InvalidArgumentException if modified is missing
     * @throws MyraException
     */
    public function resume(Domain $domain): Domain
    {
        return $this->update($domain, ['paused' => false]);
    }

    public function delete(Domain $domain): void
    {
        $this->transport->send('DELETE', 'domains', body: ['id' => $domain->id()]);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @throws InvalidArgumentException if modified is missing
     * @throws MyraException
     */
    private function update(Domain $domain, array $changes): Domain
    {
        [$id, $modified] = Persisted::versionOf($domain->id(), $domain->modified(), 'domain');

        return Domain::fromJson($this->transport->send('POST', 'domains', body: ['id' => $id, 'modified' => $modified] + $changes)->first('targetObject'));
    }
}
