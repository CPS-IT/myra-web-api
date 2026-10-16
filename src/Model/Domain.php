<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A domain (zone) of the account (`DomainVO`, the same in v1 and v2).
 */
final readonly class Domain
{
    /**
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $name,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->int('id'), $json->string('name'), $json->toArray());
    }

    /**
     * The numeric id; API v2 addresses domains by it.
     */
    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Every field Myra sent, including the ones not modelled here (e.g. `label`,
     * `maintenance`, `environment`, `permissions` in v2).
     *
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
