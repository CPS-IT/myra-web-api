<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * One of Myra's own IPv4/IPv6 networks, the ones Myra connects to your origin from
 * (`IpRangeVO`, the same in v1 and v2) — e.g. for a firewall allowlist. Read only.
 *
 * Captured: disabled ranges carry `validTo` (in the past), enabled ones do not.
 */
final readonly class IpRange
{
    /**
     * @param string $network CIDR, e.g. "192.0.2.0/24"
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $network,
        private bool $enabled,
        private ?string $validFrom = null,
        private ?string $validTo = null,
        private ?string $comment = null,
        private ?string $modified = null,
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
            $json->string('network'),
            $json->bool('enabled'),
            $json->nullableString('validFrom'),
            $json->nullableString('validTo'),
            $json->nullableString('comment'),
            $json->nullableString('modified'),
            $json->toArray(),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function network(): string
    {
        return $this->network;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * ISO 8601 as Myra sent it.
     */
    public function validFrom(): ?string
    {
        return $this->validFrom;
    }

    /**
     * ISO 8601 as Myra sent it; null = open-ended.
     */
    public function validTo(): ?string
    {
        return $this->validTo;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function modified(): ?string
    {
        return $this->modified;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
