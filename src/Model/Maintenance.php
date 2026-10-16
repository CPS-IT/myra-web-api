<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A maintenance page of a subdomain (`MaintenanceVO`, the same in v1 and v2).
 *
 * Careful: a maintenance whose start lies in the past and whose end in the future takes the
 * subdomain offline as soon as it is created (Myra activates it immediately).
 *
 * An update sends the complete object as read with the changes on top, so fields not
 * modelled here (defaultPage, …) are kept.
 */
final readonly class Maintenance
{
    /**
     * @param string $subdomain the vhost (`fqdn`), e.g. "www.example.com"
     * @param string $start ISO 8601, e.g. "2026-12-24T22:00:00+0100"
     * @param string|null $end ISO 8601; null = until deleted
     * @param string|null $content the page as HTML; null = Myra's default page or contentFrom
     * @param bool $active whether the page is shown now (Myra decides from start and end)
     * @param string|null $modified version for optimistic locking, exactly as Myra sent it
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $subdomain,
        private string $start,
        private ?string $end = null,
        private ?string $content = null,
        private bool $active = false,
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
            $json->string('fqdn'),
            $json->string('start'),
            $json->nullableString('end'),
            $json->nullableString('content'),
            $json->has('active') && $json->bool('active'),
            $json->int('id'),
            $json->nullableString('modified'),
            $json->nullableString('created'),
            $json->toArray(),
        );
    }

    /**
     * @internal
     *
     * @param bool $withActive send `active` (settable in v2; generated in v1)
     *
     * @return array<mixed>
     */
    public function toJson(bool $withActive): array
    {
        $json = ['fqdn' => $this->subdomain, 'start' => $this->start];
        if ($this->end !== null) {
            $json['end'] = $this->end;
        }
        if ($this->content !== null) {
            $json['content'] = $this->content;
        }
        if ($withActive) {
            $json['active'] = $this->active;
        }
        if ($this->id !== null) {
            $json['id'] = $this->id;
        }
        if ($this->modified !== null) {
            $json['modified'] = $this->modified;
        }
        $raw = array_diff_key($this->raw, ['objectType' => true, 'created' => true, 'permissions' => true] + ($withActive ? [] : ['active' => true]));

        return array_merge($raw, $json);
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    public function start(): string
    {
        return $this->start;
    }

    public function end(): ?string
    {
        return $this->end;
    }

    public function content(): ?string
    {
        return $this->content;
    }

    public function isActive(): bool
    {
        return $this->active;
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

    public function withStart(string $start): self
    {
        return new self($this->subdomain, $start, $this->end, $this->content, $this->active, $this->id, $this->modified, $this->created, $this->raw);
    }

    public function withEnd(?string $end): self
    {
        return new self($this->subdomain, $this->start, $end, $this->content, $this->active, $this->id, $this->modified, $this->created, $this->raw);
    }

    public function withContent(?string $content): self
    {
        return new self($this->subdomain, $this->start, $this->end, $content, $this->active, $this->id, $this->modified, $this->created, $this->raw);
    }

    /**
     * Only v2 lets the caller set it; v1 decides from start and end.
     */
    public function withActive(bool $active): self
    {
        return new self($this->subdomain, $this->start, $this->end, $this->content, $active, $this->id, $this->modified, $this->created, $this->raw);
    }
}
