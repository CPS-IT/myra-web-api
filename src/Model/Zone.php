<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A domain's zone as exported for BIND (`DomainBindVO`, the same in v1 and v2): SOA values
 * and records.
 */
final readonly class Zone
{
    /**
     * @param string $primaryDns one of Myra's name servers — it rotates between calls (captured)
     * @param int $serial the SOA serial (`version`)
     * @param list<ZoneRecord> $records
     * @param array<mixed> $raw the complete object as Myra sent it (refresh/retry/expire/minimum TTL)
     */
    public function __construct(
        private string $domain,
        private int $ttl,
        private string $primaryDns,
        private string $hostmaster,
        private int $serial,
        private array $records,
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
            $json->string('domainName'),
            $json->int('ttl'),
            $json->string('primaryDns'),
            $json->string('hostmaster'),
            $json->int('version'),
            array_map(ZoneRecord::fromJson(...), $json->objects('records')),
            $json->toArray(),
        );
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function primaryDns(): string
    {
        return $this->primaryDns;
    }

    public function hostmaster(): string
    {
        return $this->hostmaster;
    }

    public function serial(): int
    {
        return $this->serial;
    }

    /**
     * @return list<ZoneRecord>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
