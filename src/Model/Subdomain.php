<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A subdomain (vhost) Myra serves (v2 `subdomains`; no objectType). Read only — vhosts come
 * from DNS records.
 */
final readonly class Subdomain
{
    /**
     * @param string $name the fully qualified host without trailing dot (`label`)
     * @param string $domain the domain (zone) it belongs to (`domainName`)
     * @param array<mixed> $raw the complete object as Myra sent it (permissions, certId, …)
     */
    public function __construct(
        private int $id,
        private string $name,
        private string $domain,
        private int $domainId,
        private bool $active,
        private bool $paused,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self(
            $json->int('id'),
            $json->string('label'),
            $json->string('domainName'),
            $json->int('domainId'),
            $json->bool('isActive'),
            $json->bool('paused'),
            $json->toArray(),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    /**
     * E.g. "www.example.com".
     */
    public function name(): string
    {
        return $this->name;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function domainId(): int
    {
        return $this->domainId;
    }

    /**
     * Protected by Myra (`isActive`).
     */
    public function active(): bool
    {
        return $this->active;
    }

    public function paused(): bool
    {
        return $this->paused;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
