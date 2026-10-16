<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A DNS record of a domain (`DnsRecordVO`, the same in v1 and v2).
 */
final readonly class DnsRecord
{
    /**
     * @param string $name the fully qualified host name, e.g. "www.example.com"
     * @param string $recordType A, AAAA, CNAME, MX, TXT, …
     * @param string|null $alternativeCname the Myra CNAME to point the host to
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $name,
        private string $recordType,
        private string $value,
        private int $ttl,
        private bool $active,
        private bool $enabled,
        private ?string $alternativeCname = null,
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
            $json->string('name'),
            $json->string('recordType'),
            $json->string('value'),
            $json->int('ttl'),
            $json->bool('active'),
            $json->bool('enabled'),
            $json->nullableString('alternativeCname'),
            $json->toArray(),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function recordType(): string
    {
        return $this->recordType;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * Traffic for this host goes through Myra (proxied), not straight to the origin.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function alternativeCname(): ?string
    {
        return $this->alternativeCname;
    }

    /**
     * Every field Myra sent, including the ones not modelled here (e.g. `priority`,
     * `paused`, `upstreamOptions`, `endpoints`, `comment`).
     *
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
