<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A user group (`GroupVO`, v2 only).
 */
final readonly class Group
{
    /**
     * @param list<string> $roles
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $name,
        private string $type,
        private array $roles,
        private int $membersCount,
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
            $json->string('type'),
            $json->has('roles') ? $json->strings('roles') : [],
            $json->int('membersCount'),
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

    /**
     * E.g. "USER".
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function membersCount(): int
    {
        return $this->membersCount;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
