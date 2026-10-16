<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * An IP filter entry of a subdomain (`IpFilterVO`, the same in v1 and v2).
 *
 * New entries are built with the constructor and have no id; changes go through the
 * with…() methods on an entry read before (updates and deletes need `id` and `modified`).
 */
final readonly class IpFilter
{
    public const TYPE_WHITELIST = 'WHITELIST';
    public const TYPE_BLACKLIST = 'BLACKLIST';
    public const TYPE_WHITELIST_REQUEST_LIMITER = 'WHITELIST_REQUEST_LIMITER';

    /**
     * @param string $subdomain the vhost, e.g. "www.example.com" (`subDomainName`)
     * @param string $type TYPE_WHITELIST, TYPE_BLACKLIST or TYPE_WHITELIST_REQUEST_LIMITER
     * @param string $value an IP address or a CIDR range, e.g. "192.0.2.0/24" (IPv6: single
     *                      addresses only for whitelist and blacklist)
     * @param string|null $expireDate until when the entry applies (ISO 8601), null = forever —
     *                                set by the v2 CSV import (captured); setting it through
     *                                create/update is not captured yet
     * @param string|null $modified version for optimistic locking, exactly as Myra sent it
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $subdomain,
        private string $type,
        private string $value,
        private bool $enabled = true,
        private ?string $comment = null,
        private ?string $expireDate = null,
        private ?int $id = null,
        private ?string $modified = null,
        private ?string $created = null,
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
            $json->string('subDomainName'),
            $json->string('type'),
            $json->string('value'),
            $json->bool('enabled'),
            $json->nullableString('comment'),
            $json->nullableString('expireDate'),
            $json->int('id'),
            $json->string('modified'),
            $json->nullableString('created'),
            $json->toArray(),
        );
    }

    /**
     * @internal
     *
     * @return array<string, scalar>
     */
    public function toJson(): array
    {
        $json = ['subDomainName' => $this->subdomain, 'type' => $this->type, 'value' => $this->value, 'enabled' => $this->enabled];
        if ($this->comment !== null) {
            $json['comment'] = $this->comment;
        }
        if ($this->expireDate !== null) {
            $json['expireDate'] = $this->expireDate;
        }
        if ($this->id !== null) {
            $json['id'] = $this->id;
        }
        if ($this->modified !== null) {
            $json['modified'] = $this->modified;
        }

        return $json;
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function expireDate(): ?string
    {
        return $this->expireDate;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function modified(): ?string
    {
        return $this->modified;
    }

    public function created(): ?string
    {
        return $this->created;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function withType(string $type): self
    {
        return new self($this->subdomain, $type, $this->value, $this->enabled, $this->comment, $this->expireDate, $this->id, $this->modified, $this->created, $this->raw);
    }

    public function withValue(string $value): self
    {
        return new self($this->subdomain, $this->type, $value, $this->enabled, $this->comment, $this->expireDate, $this->id, $this->modified, $this->created, $this->raw);
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($this->subdomain, $this->type, $this->value, $enabled, $this->comment, $this->expireDate, $this->id, $this->modified, $this->created, $this->raw);
    }

    public function withExpireDate(?string $expireDate): self
    {
        return new self($this->subdomain, $this->type, $this->value, $this->enabled, $this->comment, $expireDate, $this->id, $this->modified, $this->created, $this->raw);
    }

    public function withComment(?string $comment): self
    {
        return new self($this->subdomain, $this->type, $this->value, $this->enabled, $comment, $this->expireDate, $this->id, $this->modified, $this->created, $this->raw);
    }
}
