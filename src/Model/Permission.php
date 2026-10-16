<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A permission of a user or group (v2 only): an action on a kind of object (`objectType`: Domain, DnsRedirect, …), optionally on one object.
 */
final readonly class Permission
{
    /**
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $objectType,
        private string $permissionType,
        private string $action,
        private ?string $name,
        private ?int $objectId,
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
            $json->string('objectType'),
            $json->string('objectPermissionType'),
            $json->string('action'),
            $json->nullableString('name'),
            $json->has('objectInstance') && is_int($json->toArray()['objectInstance']) ? $json->int('objectInstance') : null,
            $json->toArray(),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    /**
     * The kind of object, e.g. "Domain".
     */
    public function objectType(): string
    {
        return $this->objectType;
    }

    /**
     * "USER" or "GROUP" — whom it was given to.
     */
    public function permissionType(): string
    {
        return $this->permissionType;
    }

    /**
     * E.g. "READ", "EDIT", "CREATE", "CACHE_CLEAR".
     */
    public function action(): string
    {
        return $this->action;
    }

    /**
     * The object it applies to, if one.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * The id of that object (`objectInstance`).
     */
    public function objectId(): ?int
    {
        return $this->objectId;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
