<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A DNS record of a domain (`DnsRecordVO`, the same in v1 and v2).
 *
 * New records are built with the constructor and have no id; changes go through the
 * with…() methods on a record read before. An update sends the complete record as Myra
 * sent it with the changes on top, so fields not modelled here (SRV port/weight, CAA,
 * sslCertTemplate, endpoints, …) are kept, whatever Myra does with missing fields.
 */
final readonly class DnsRecord
{
    public const SERVICE_HTTP = 1;
    public const SERVICE_MAIL = 2;
    public const SERVICE_OTHER = 3;

    /**
     * @param string $name the fully qualified host name, e.g. "www.example.com"
     * @param string $recordType A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, …
     * @param bool $active traffic goes through Myra (protected), not straight to the value
     * @param int $priority MX priority
     * @param UpstreamOptions|null $upstreamOptions load balancing (A, AAAA, CNAME)
     * @param string|null $alternativeCname the Myra CNAME to point the host to (read only)
     * @param string|null $modified version for optimistic locking, exactly as Myra sent it
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $name,
        private string $recordType,
        private string $value,
        private int $ttl = 300,
        private bool $active = false,
        private bool $enabled = true,
        private int $priority = 0,
        private ?string $comment = null,
        private ?UpstreamOptions $upstreamOptions = null,
        private ?string $alternativeCname = null,
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
            $json->string('name'),
            $json->string('recordType'),
            $json->string('value'),
            $json->int('ttl'),
            $json->bool('active'),
            $json->bool('enabled'),
            $json->has('priority') ? $json->int('priority') : 0,
            $json->nullableString('comment'),
            self::upstreamOptionsOf($json),
            $json->nullableString('alternativeCname'),
            $json->int('id'),
            $json->nullableString('modified'),
            $json->nullableString('created'),
            $json->toArray(),
        );
    }

    /**
     * Load balancing settings, if the record has a complete UpstreamOptionsVO; partial ones
     * (captured: a TXT record answers only backup and down) stay in raw() and are sent back
     * unchanged — a record must stay readable whatever its extras look like.
     *
     * @throws TransportException
     */
    private static function upstreamOptionsOf(JsonObject $json): ?UpstreamOptions
    {
        $value = $json->toArray()['upstreamOptions'] ?? null;
        if (!is_array($value) || !array_key_exists('weight', $value)) {
            return null;
        }

        return UpstreamOptions::fromJson($json->object('upstreamOptions'));
    }

    /**
     * The request body: for a new record its fields; for one read before the complete object
     * as Myra sent it with the changed fields on top.
     *
     * @internal
     *
     * @return array<mixed>
     */
    public function toJson(): array
    {
        $json = [
            'name' => $this->name,
            'recordType' => $this->recordType,
            'value' => $this->value,
            'ttl' => $this->ttl,
            'active' => $this->active,
            'enabled' => $this->enabled,
            'priority' => $this->priority,
        ];
        if ($this->comment !== null) {
            $json['comment'] = $this->comment;
        }
        if ($this->upstreamOptions !== null) {
            $json['upstreamOptions'] = $this->upstreamOptions->toJson();
        }
        if ($this->id !== null) {
            $json['id'] = $this->id;
        }
        if ($this->modified !== null) {
            $json['modified'] = $this->modified;
        }

        return array_merge(array_diff_key($this->raw, ['objectType' => true, 'created' => true]), $json);
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

    public function priority(): int
    {
        return $this->priority;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function upstreamOptions(): ?UpstreamOptions
    {
        return $this->upstreamOptions;
    }

    public function alternativeCname(): ?string
    {
        return $this->alternativeCname;
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
     * Every field Myra sent, including the ones not modelled here (e.g. `paused`,
     * `endpoints`, `vhostId`, `serviceType`, `caaFlags`).
     *
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function withValue(string $value): self
    {
        return $this->with(['value' => $value]);
    }

    public function withTtl(int $ttl): self
    {
        return $this->with(['ttl' => $ttl]);
    }

    public function withActive(bool $active): self
    {
        return $this->with(['active' => $active]);
    }

    public function withEnabled(bool $enabled): self
    {
        return $this->with(['enabled' => $enabled]);
    }

    public function withPriority(int $priority): self
    {
        return $this->with(['priority' => $priority]);
    }

    public function withComment(?string $comment): self
    {
        return $this->with(['comment' => $comment]);
    }

    public function withUpstreamOptions(?UpstreamOptions $upstreamOptions): self
    {
        return $this->with(['upstreamOptions' => $upstreamOptions]);
    }

    /**
     * @param array{value?: string, ttl?: int, active?: bool, enabled?: bool, priority?: int, comment?: string|null, upstreamOptions?: UpstreamOptions|null} $changes
     */
    private function with(array $changes): self
    {
        return new self(
            $this->name,
            $this->recordType,
            $changes['value'] ?? $this->value,
            $changes['ttl'] ?? $this->ttl,
            $changes['active'] ?? $this->active,
            $changes['enabled'] ?? $this->enabled,
            $changes['priority'] ?? $this->priority,
            array_key_exists('comment', $changes) ? $changes['comment'] : $this->comment,
            array_key_exists('upstreamOptions', $changes) ? $changes['upstreamOptions'] : $this->upstreamOptions,
            $this->alternativeCname,
            $this->id,
            $this->modified,
            $this->created,
            $this->raw,
        );
    }
}
