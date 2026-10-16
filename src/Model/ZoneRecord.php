<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A record of a zone as exported for BIND (in `DomainBindVO.records`, the same in v1 and v2).
 */
final readonly class ZoneRecord
{
    /**
     * @param string $domain the record's name, e.g. "www.example.com"
     * @param array<mixed> $raw the complete object as Myra sent it (rtype, cnameAlt, …)
     */
    public function __construct(
        private string $domain,
        private string $type,
        private string $value,
        private ?int $ttl = null,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->string('domain'), $json->string('type'), $json->string('value'), $json->has('ttl') ? $json->int('ttl') : null, $json->toArray());
    }

    public function domain(): string
    {
        return $this->domain;
    }

    /**
     * E.g. "A", "AAAA", "CNAME", "NS".
     */
    public function type(): string
    {
        return $this->type;
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * Null for the name server records (the zone's TTL applies).
     */
    public function ttl(): ?int
    {
        return $this->ttl;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
